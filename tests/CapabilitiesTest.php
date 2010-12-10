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


require_once(
    dirname(__FILE__) .
    DIRECTORY_SEPARATOR . 'testenv' .
    DIRECTORY_SEPARATOR . 'bootstrap.php'
);

class   ServerCapabilitiesTest
extends ErebotModuleTestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->_module = new Erebot_Module_ServerCapabilities(
            $this->_connection,
            NULL
        );
        $this->_module->reload(Erebot_Module_Base::RELOAD_MEMBERS);
    }

    public function tearDown()
    {
        parent::tearDown();
        unset($this->_module);
    }

    public function testISupport()
    {
        $raw = new Erebot_Event_Raw(
            $this->_connection,
            Erebot_Interface_Event_Raw::RPL_ISUPPORT,
            'source', 'target',
            'CMDS=KNOCK,MAP,DCCALLOW,USERIP NAMESX SAFELIST HCN '.
            'MAXCHANNELS=20 CHANLIMIT=#:20 MAXLIST=b:60,e:60,I:60 '.
            'NICKLEN=30 CHANNELLEN=32 TOPICLEN=307 KICKLEN=307 '.
            'AWAYLEN=307 MAXTARGETS=20 :are supported by this server'
        );
        $this->_module->handleRaw($raw);
        $this->assertEquals(FALSE, $this->_module->hasExtendedList());
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
            Erebot_Module_ServerCapabilities::LIST_BANS
        ));
#        $this->assertEquals(60, $this->_module->getMaxListSize(
#            Erebot_Module_ServerCapabilities::LIST_EXCEPTS
#        ));
#        $this->assertEquals(60, $this->_module->getMaxListSize(
#            Erebot_Module_ServerCapabilities::LIST_INVITES
#        ));
        $this->assertEquals(NULL, $this->_module->getMaxListSize(
            Erebot_Module_ServerCapabilities::LIST_SILENCES
        ));
        $this->assertEquals(20, $this->_module->getChanLimit('#foo'));
        $this->assertEquals(-1, $this->_module->getChanLimit('&foo'));
        $this->assertEquals(-1, $this->_module->getChanLimit('!foo'));
    }
}

