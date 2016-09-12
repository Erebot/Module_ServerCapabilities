<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

abstract class  TextWrapper
implements      \Erebot\Interfaces\TextWrapper
{
    private $_chunks;

    public function __construct($text)
    {
        $this->_chunks = explode(' ', $text);
    }

    public function __toString()
    {
        return implode(' ', $this->_chunks);
    }

    public function getTokens($start, $length = 0, $separator = " ")
    {
        if ($length !== 0)
            return implode(" ", array_slice($this->_chunks, $start, $length));
        return implode(" ", array_slice($this->_chunks, $start));
    }

    public function offsetGet($offset)
    {
        return $this->_chunks[$offset];
    }

    public function count()
    {
        return count($this->_chunks);
    }
}

class   ServerCapabilitiesTest
extends Erebot_Testenv_Module_TestCase
{
    protected function _mockNumeric($num, $source, $target, $text)
    {
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\Numeric')->getMock();
        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getCode')
            ->will($this->returnValue($num));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue($source));
        $event
            ->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue($target));
        $event
            ->expects($this->any())
            ->method('getText')
            ->will($this->returnValue($text));
        return $event;
    }

    public function setUp()
    {
        $this->_module = new \Erebot\Module\ServerCapabilities(NULL);
        parent::setUp();

        $profile = $this->getMockBuilder('\\Erebot\\NumericProfile\\Base')
            ->setMethods(array('offsetGet'))
            ->getMock();
        $profile
            ->expects($this->any())
            ->method('offsetGet')
            ->will($this->returnCallback(array($this, 'getNumericByName')));

        $this->_connection
            ->expects($this->any())
            ->method('getNumericProfile')
            ->will($this->returnValue($profile));

        $this->_module->reloadModule(
            $this->_connection,
            \Erebot\Module\Base::RELOAD_MEMBERS |
            \Erebot\Module\Base::RELOAD_INIT
        );
    }

    public function tearDown()
    {
        $this->_module->unloadModule();
        parent::tearDown();
    }

    public function getNumericByName($name)
    {
        if ($name === 'RPL_ISUPPORT')
            return 5;
    }

    public function testISupport()
    {
        $numeric = $this->_mockNumeric(
            005, 'source', 'target',
            'CMDS=KNOCK,MAP,DCCALLOW,USERIP NAMESX SAFELIST HCN '.
            'MAXCHANNELS=20 CHANLIMIT=#:20 MAXLIST=b:60,e:60,I:60 '.
            'NICKLEN=30 CHANNELLEN=32 TOPICLEN=307 KICKLEN=307 '.
            'AWAYLEN=307 MAXTARGETS=20 :are supported by this server'
        );
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $listExtensions = array(
            \Erebot\Module\ServerCapabilities::ELIST_MASK,
            \Erebot\Module\ServerCapabilities::ELIST_NEG_MASK,
            \Erebot\Module\ServerCapabilities::ELIST_USERS,
            \Erebot\Module\ServerCapabilities::ELIST_CREATION,
            \Erebot\Module\ServerCapabilities::ELIST_TOPIC,
        );
        foreach ($listExtensions as $ext)
            $this->assertEquals(FALSE, $this->_module->hasListExtension($ext));
        $this->assertEquals(TRUE, $this->_module->hasExtendedNames());
        $this->assertEquals(FALSE, $this->_module->hasExtraPenalty());
        $this->assertEquals(FALSE, $this->_module->hasForcedNickChange());
        $this->assertEquals(TRUE, $this->_module->hasHybridConnectNotice());
        $this->assertEquals(TRUE, $this->_module->hasCommand('knock'));
        $this->assertEquals(TRUE, $this->_module->hasCommand('map'));
        $this->assertEquals(TRUE, $this->_module->hasCommand('dccallow'));
        $this->assertEquals(TRUE, $this->_module->hasCommand('userip'));
        $this->assertEquals(FALSE, $this->_module->hasCommand('inexistent'));
        $this->assertEquals(TRUE, $this->_module->hasSafeList());
        $this->assertEquals(FALSE, $this->_module->hasSecureList());
        $this->assertEquals(FALSE, $this->_module->hasStartTLS());
        $this->assertEquals(FALSE, $this->_module->hasStatusMsg('@'));
        $this->assertEquals(FALSE, $this->_module->hasStatusMsg('%'));
        $this->assertEquals(FALSE, $this->_module->hasStatusMsg('+'));
        $this->assertEquals(FALSE, $this->_module->hasStatusMsg('!'));
        $this->assertEquals(FALSE, $this->_module->hasStatusMsg('~'));
        $this->assertEquals(TRUE, $this->_module->isChannel('#foo'));
        $this->assertEquals(FALSE, $this->_module->isChannel('&foo'));
        $this->assertEquals(FALSE, $this->_module->isChannel('!foo'));
        $this->assertEquals(60, $this->_module->getMaxListSize(
            \Erebot\Module\ServerCapabilities::LIST_BANS
        ));
        $this->assertEquals(NULL, $this->_module->getMaxListSize(
            \Erebot\Module\ServerCapabilities::LIST_SILENCES
        ));
        $this->assertEquals(20, $this->_module->getChanLimit('#foo'));
    }

    /**
     * @expectedException \Erebot\InvalidValueException
     */
    public function testISupport2()
    {
        $numeric = $this->_mockNumeric(
            005, 'source', 'target',
            'CMDS=KNOCK,MAP,DCCALLOW,USERIP NAMESX SAFELIST HCN '.
            'MAXCHANNELS=20 CHANLIMIT=#:20 MAXLIST=b:60,e:60,I:60 '.
            'NICKLEN=30 CHANNELLEN=32 TOPICLEN=307 KICKLEN=307 '.
            'AWAYLEN=307 MAXTARGETS=20 :are supported by this server'
        );
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $this->assertEquals(-1, $this->_module->getChanLimit('&foo'));
    }

    /**
     * @expectedException \Erebot\InvalidValueException
     */
    public function testISupport3()
    {
        $numeric = $this->_mockNumeric(
            005, 'source', 'target',
            'CMDS=KNOCK,MAP,DCCALLOW,USERIP NAMESX SAFELIST HCN '.
            'MAXCHANNELS=20 CHANLIMIT=#:20 MAXLIST=b:60,e:60,I:60 '.
            'NICKLEN=30 CHANNELLEN=32 TOPICLEN=307 KICKLEN=307 '.
            'AWAYLEN=307 MAXTARGETS=20 :are supported by this server'
        );
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $this->assertEquals(-1, $this->_module->getChanLimit('!foo'));
    }

    /**
     * @expectedException \Erebot\NotFoundException
     */
    public function testSSL1()
    {
        $numeric = $this->_mockNumeric(005, 'source', 'target', '');
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $this->_module->getSSL();
    }

    public function testSSL2()
    {
        $numeric = $this->_mockNumeric(005, 'source', 'target', 'SSL=');
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $this->assertEquals(array(), $this->_module->getSSL());
    }

    public function testSSL3()
    {
        $numeric = $this->_mockNumeric(
            005, 'source', 'target',
            'SSL=127.0.0.1:7002'
        );
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $this->assertEquals(
            array('127.0.0.1' => 7002),
            $this->_module->getSSL()
        );
    }

    public function testSSL4()
    {
        $numeric = $this->_mockNumeric(
            005, 'source', 'target',
            'SSL=1.2.3.4:6668;4.3.2.1:6669;*:6660;'
        );
        $this->_module->handleNumeric($this->_numericHandler, $numeric);
        $expected = array(
            '1.2.3.4'   => 6668,
            '4.3.2.1'   => 6669,
            '*'         => 6660,
        );
        $this->assertEquals($expected, $this->_module->getSSL());
    }

    public function testHelp()
    {
        $wordsClass = $this->getMockForAbstractClass(
            'TextWrapper',
            array(),
            '',
            FALSE,
            FALSE
        );
        $words = new $wordsClass('Erebot\\Module\\ServerCapabilities');

        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\ChanText')->getMock();
        $event
            ->expects($this->any())
            ->method('getChan')
            ->will($this->returnValue('#test'));

        $this->assertTrue($this->_module->getHelp($event, $words));
    }
}

