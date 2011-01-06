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

class   Erebot_Module_ServerCapabilities
extends Erebot_Module_Base
{
    const LIST_BANS         = 0;
    const LIST_SILENCES     = 1;
    const LIST_EXCEPTS      = 2;
    const LIST_INVITES      = 3;
    const LIST_WATCHES      = 4;

    const TEXT_CHAN_NAME    = 0;
    const TEXT_NICKNAME     = 1;
    const TEXT_TOPIC        = 2;
    const TEXT_KICK         = 3;
    const TEXT_AWAY         = 4;

    const MODE_TYPE_A       = 0;
    const MODE_TYPE_B       = 1;
    const MODE_TYPE_C       = 2;
    const MODE_TYPE_D       = 3;

    const ELIST_MASK        = 'M';
    const ELIST_NEG_MASK    = 'N';
    const ELIST_USERS       = 'U';
    const ELIST_CREATION    = 'C';
    const ELIST_TOPIC       = 'T';

    const PATTERN_PREFIX    = '/^\\(([^\\)]+)\\)(.*)$/';

    protected   $_supported;
    protected   $_parsed;

    public function reload($flags)
    {
        if ($this->_channel !== NULL)
            return;

        if ($flags & self::RELOAD_INIT) {
            $this->_supported   = array();
            $this->_parsed      = FALSE;
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handler = new Erebot_RawHandler(
                array($this, 'handleRaw'),
                Erebot_Interface_Event_Raw::RPL_ISUPPORT
            );
            $this->_connection->addRawHandler($handler);

            $handler = new Erebot_RawHandler(
                array($this, 'handleRaw'),
                Erebot_Interface_Event_Raw::RPL_LUSERCLIENT
            );
            $this->_connection->addRawHandler($handler);
        }
    }

    public function handleRaw(Erebot_Event_Raw $raw)
    {
        $rawCode = $raw->getRaw();
        if ($rawCode == Erebot_Interface_Event_Raw::RPL_LUSERCLIENT && !$this->_parsed) {
            $this->_parsed = TRUE;
            $event = new Erebot_Event_ServerCapabilities(
                $this->_connection,
                $this
            );
            $this->_connection->dispatchEvent($event);
            return;
        }

        if ($rawCode != Erebot_Interface_Event_Raw::RPL_ISUPPORT)
            return;

        $tokens = explode(' ', $raw->getText());
        foreach ($tokens as &$token) {
            if (substr($token, 0, 1) == ':')
                break;

            $supported          = $this->_parseToken($token);
            $this->_supported = array_merge($this->_supported, $supported);
        }
        unset($token);
    }

    protected function _parseToken($token)
    {
        $pos = strpos($token, '=');
        if ($pos === FALSE)
            return array(strtoupper($token) => TRUE);

        $name   = strtoupper(substr($token, 0, $pos));
        $value  = substr($token, $pos + 1);

        if ($value == '')
            return array(strtoupper($name) => TRUE);

        $subs   = explode(',', $value);
        if (count($subs) == 1) {
            $colonPos       = strpos($subs[0], ':');
            $semicolonPos   = strpos(trim($subs[0], ';'), ';');
            if ($colonPos === FALSE) {
                $subs   = explode(';', $value);
                if (count($subs) == 1)
                    return array($name => $value);
            }
            else if ($semicolonPos !== FALSE && $semicolonPos > $colonPos) {
                $subs = explode(';', trim($subs[0], ';'));
            }
        }

        $res = array();
        foreach ($subs as $sub) {
            if (strpos($sub, ':') === FALSE) {
                $res[$name][] = $sub;
                continue;
            }

            list($key, $val) = explode(':', $sub);
            $res[$name][$key] = $val;
        }
        return $res;
    }

    /**
     * Whether the server supports extensions for the LIST
     * command or not.
     * Extended LIST allows one to search channels not only
     * by their name but also using other criteria such as
     * their topic, creation time, user count, etc.
     *
     * \param opaque $extension
     *      The extension for which support must be tested.
     *      Use the Erebot_Module_ServerCapabilities::ELIST_*
     *      series of constant for this parameter.
     *
     * \retval bool
     *      TRUE if the given $extension is supported,
     *      FALSE otherwise.
     */
    public function hasListExtension($extension)
    {
        if (!isset($this->_supported['ELIST']))
            return FALSE;
        if (!is_string($extension) || strlen($extension) != 1)
            return FALSE;
        return (strpos($this->_supported['ELIST'], $extension) !== FALSE);
    }

    /**
     * Whether extended names are supported by this server.
     * Extended names make it possible to retrieve all modes
     * set for a particular user on a channel using the
     * /NAMES command.
     * This extension is disabled by default and must be
     * explicitly enabled by sending a "PROTOCTL NAMESX"
     * message to the server.
     * When this extension is disabled, the server will only
     * send the highest mode of each user in reply to /NAMES.
     *
     * \retval bool
     *      TRUE if the server supports the NAMESX extension,
     *      FALSE otherwise.
     */
    public function hasExtendedNames()
    {
        return isset($this->_supported['NAMESX']);
    }

    /**
     * Whether the server can send full the user!ident@host
     * in a reply to a NAMES command. This makes it possible
     * to build an internal list of addresses without requiring
     * an extra /WHO or /WHOIS command.
     * This extension is disabled by default and must be
     * explicitly enabled by sending a "PROTOCTL UHNAMES"
     * message to the server.
     * When this extension is disabled, the server will only
     * send the user's nickname in reply to /NAMES.
     *
     * \retval bool
     *      TRUE if the server supports the UHNAMES extension,
     *      FALSE otherwise.
     */
    public function hasUserHostNames()
    {
        return isset($this->_supported['UHNAMES']);
    }

    public function hasExtraPenalty()
    {
        return isset($this->_supported['PENALTY']);
    }

    public function hasForcedNickChange()
    {
        return isset($this->_supported['FNC']);
    }

    /**
     * Whether the server supports the Hybrid Connect Notice
     * extension. This extension is aimed at IRC operators
     * and (more likely) IRC services.
     * The Hybrid Connect Notice (HCN) slightly changes the
     * format used by connection notices so that it matches
     * the format expected by the Blitzed Open Proxy Monitor
     * (http://wiki.blitzed.org/BOPM). This includes displaying
     * the user's IP address in connect/exit notices. 
     *
     * \retval bool
     *      TRUE if the server supports the HCN extension,
     *      FALSE otherwise.
     */
    public function hasHybridConnectNotice()
    {
        return isset($this->_supported['HCN']);
    }

    /**
     * Indicates whether a particular command is supported
     * or not.
     *
     * \param string $cmdName
     *      Name of the command whose support must be tested.
     *
     * \retval bool
     *      TRUE if the given command is supported,
     *      FALSE otherwise.
     *
     * \throw Erebot_InvalidValueException
     *      The given $cmdName is not a valid command name.
     *
     * \note
     *      This method only exists to test support for
     *      commands which are not yet widely recognized
     *      by IRC clients. Basic commands such as those
     *      described in RFC 1459 and its successors need
     *      not be tested (this method will indeed return
     *      FALSE for such commands).
     *
     * \note
     *      Many commands actually have separate methods
     *      to check for their support. This is a limitation
     *      of the current implementation. Those methods will
     *      probably be merged in hasCommand() later on.
     */
    public function hasCommand($cmdName)
    {
        if (!is_string($cmdName)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException($translator->gettext(
                'Not a valid command name'));
        }
        $cmdName = strtoupper($cmdName);

        if (isset($this->_supported[$cmdName]))
            return TRUE;

        if (isset($this->_supported['CMDS']) &&
            in_array($cmdName, $this->_supported['CMDS']))
            return TRUE;
        return FALSE;
    }

    public function hasSafeList()
    {
        return isset($this->_supported['SAFELIST']);
    }

    public function hasSecureList()
    {
        return isset($this->_supported['SECURELIST']);
    }

    /**
     * Whether the server supports the STARTTLS command.
     * This is only an indication so that clients using
     * a plain-text connection may choose to disconnect
     * and reconnect using a TLS encrypted connection.
     *
     * \retval bool
     *      TRUE if the server support TLS encrypted
     *      connections, FALSE otherwise.
     */
    public function hasStartTLS()
    {
        return isset($this->_supported['STARTTLS']);
    }

    public function hasStatusMsg($status)
    {
        if (!is_string($status) || strlen($status) != 1) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid status'));
        }

        if (isset($this->_supported['STATUSMSG']) &&
            is_string($this->_supported['STATUSMSG'])) {
            if (strpos($this->_supported['STATUSMSG'], $status) !== FALSE)
                return TRUE;
        }

        if ($status == '+' && isset($this->_supported['WALLVOICES']))
            return TRUE;

        if ($status == '@' && isset($this->_supported['WALLCHOPS']))
            return TRUE;

        return FALSE;
    }

    /**
     * Checks whether a given string contains a valid channel name.
     *
     * \param string $chan
     *      Potentiel channel name to test.
     *
     * \retval bool
     *      TRUE if the given $chan can be used as a channel name,
     *      FALSE otherwise.
     *
     * \note
     *      This method uses very basic checks to test validity
     *      of the $chan as a channel name. Therefore, a server
     *      may reject a channel name which was recognized as
     *      being valid by this method.
     */
    public function isChannel($chan)
    {
        if (!is_string($chan) || !strlen($chan)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException($translator->gettext(
                'Bad channel name'));
        }

        $prefix     = $chan[0];
        if (isset($this->_supported['CHANTYPES']) &&
            is_string($this->_supported['CHANTYPES']))
            $allowed = $this->_supported['CHANTYPES'];
        else if (isset($this->_supported['CHANLIMIT']) &&
                is_array($this->_supported['CHANLIMIT']) &&
                count($this->_supported['CHANLIMIT']))
            $allowed = implode('', array_keys($this->_supported['CHANLIMIT']));
        else
            // As per RFC 2811 - (2.1) Namespace
            $allowed = '#&+!';

        // Restricted characters in channel names,
        // as per RFC 2811 - (2.1) Namespace.
        foreach (array(' ', ',', "\x07", ':') as $token)
            if (strpos($token, $chan) !== FALSE)
                return FALSE;

        if (strlen($chan) > 50)
            return FALSE;

        return (strpos($allowed, $prefix) !== FALSE);
    }

    public function getMaxListSize($list)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_int($list))
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid list type'));

        switch ($list) {
            case self::LIST_BANS:
            case self::LIST_EXCEPTS:
            case self::LIST_INVITES:
                $mode = $this->getChanListMode($list);
                if (isset($this->_supported['MAXLIST'][$mode]) &&
                    ctype_digit($this->_supported['MAXLIST'][$mode]))
                    return (int) $this->_supported['MAXLIST'][$mode];
                if ($list == self::LIST_BANS &&
                    isset($this->_supported['MAXBANS']) &&
                    ctype_digit($this->_supported['MAXBANS']))
                    return (int) $this->_supported['MAXBANS'];
                return NULL;

            case self::LIST_SILENCES:
                if (isset($this->_supported['SILENCE']) &&
                    ctype_digit($this->_supported['SILENCE']))
                    return (int) $this->_supported['SILENCE'];
                return NULL;

            case self::LIST_WATCHES:
                if (isset($this->_supported['WATCH']) &&
                    ctype_digit($this->_supported['WATCH']))
                    return (int) $this->_supported['WATCH'];
                return NULL;

            default:
                throw new Erebot_InvalidValueException($translator->gettext(
                    'Invalid list type'));
        }
    }

    public function getChanLimit($chanPrefix)
    {
        if (!$this->isChannel($chanPrefix))
            return -1;

        if (isset($this->_supported['CHANLIMIT']) &&
            is_array($this->_supported['CHANLIMIT'])) {
            foreach ($this->_supported['CHANLIMIT'] as $prefixes => $limit) {
                if (strpos($prefixes, $chanPrefix) !== FALSE) {
                    if ($limit == '')
                        return -1;

                    if (ctype_digit($limit))
                        return (int) $limit;
                }
            }
        }

        if (isset($this->_supported['MAXCHANNELS']) &&
            ctype_digit($this->_supported['MAXCHANNELS']))
            return (int) $this->_supported['MAXCHANNELS'];
        return -1;
    }

    public function getMaxTextLen($type)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_int($type))
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid text type'));

        switch ($type) {
            case self::TEXT_AWAY:
                if (isset($this->_supported['AWAYLEN']) &&
                    ctype_digit($this->_supported['AWAYLEN']))
                    return (int) $this->_supported['AWAYLEN'];
                break;

            case self::TEXT_CHAN_NAME:
                if (isset($this->_supported['CHANNELLEN']) &&
                    ctype_digit($this->_supported['CHANNELLEN']))
                    return (int) $this->_supported['CHANNELLEN'];
                return 200;

            case self::TEXT_KICK:
                if (isset($this->_supported['KICKLEN']) &&
                    ctype_digit($this->_supported['KICKLEN']))
                    return (int) $this->_supported['KICKLEN'];
                break;

            case self::TEXT_NICKNAME:
                if (isset($this->_supported['NICKLEN']) &&
                    ctype_digit($this->_supported['NICKLEN']))
                    return (int) $this->_supported['NICKLEN'];
                return 9;

            case self::TEXT_TOPIC:
                if (isset($this->_supported['TOPICLEN'])) {
                    if (ctype_digit($this->_supported['TOPICLEN']))
                        return (int) $this->_supported['TOPICLEN'];
                }
                return -1;

            default:
                throw new Erebot_InvalidValueException($translator->gettext(
                    'Invalid text type'));
        }
        throw new Erebot_NotFoundException($translator->gettext(
            'No limit defined for this text'));
    }

    /**
     * Returns the name of the case mapping currently
     * used by the server.
     *
     * \retval string
     *      The mapping in use. If this information is not
     *      available, this method assumes the mapping
     *      defined in RFC 1459 ("rfc1459") is used.
     */
    public function getCaseMapping()
    {
        if (isset($this->_supported['CASEMAPPING']) &&
            is_string($this->_supported['CASEMAPPING']))
            return strtolower($this->_supported['CASEMAPPING']);
        return 'rfc1459';
    }

    /**
     * Returns the charset used by this IRC server.
     *
     * \retval string
     *      The server's charset.
     *
     * \throw Erebot_NotFoundException
     *      The server did not declare its charset.
     */
    public function getCharset()
    {
        if (isset($this->_supported['CHARSET']) &&
            is_string($this->_supported['CHARSET']))
            return $this->_supported['CHARSET'];
        $translator = $this->getTranslator(NULL);
        throw new Erebot_NotFoundException($translator->gettext(
            'No charset specified'));
    }

    /**
     * The IRC network this server belongs to.
     *
     * \retval string
     *      Name of the IRC network this server belongs to.
     *
     * \throw Erebot_NotFoundException
     *      The server did not declare itself as being part
     *      of any specific IRC network.
     *
     * \warning
     *      The name return by this method is purely informative.
     *      The server is free to send any name it chooses.
     *      Don't rely on this to implement security checks!
     */
    public function getNetworkName()
    {
        if (isset($this->_supported['NETWORK']) &&
            is_string($this->_supported['NETWORK']))
            return $this->_supported['NETWORK'];
        throw new Erebot_NotFoundException($translator->gettext(
            'No network declared'));
    }

    public function getChanListMode($list)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_int($list))
            throw new Erebot_InvalidValueException($translator->gettext(
                'Bad channel list ID'));

        switch ($list) {
            case self::LIST_BANS:
                return 'b';

            case self::LIST_EXCEPTS:
                if (!isset($this->_supported['EXCEPTS']))
                    throw new Erebot_NotFoundException($translator->gettext(
                        'Excepts are not available on this server'));

                if ($this->_supported['EXCEPTS'] === TRUE)
                    return 'e';
                return $this->_supported['EXCEPTS'];
                break;

            case self::LIST_INVITES:
                if (!isset($this->_supported['INVEX']))
                    throw new Erebot_NotFoundException($translator->gettext(
                        'Invites are not available on this server'));

                if ($this->_supported['INVEX'] === TRUE)
                    return 'I';
                return $this->_supported['INVEX'];
                break;

            default:
                throw new Erebot_InvalidValueException($translator->gettext(
                    'Invalid channel list ID'));
        }
    }

    public function getChanPrefixForMode($mode)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_string($mode) || strlen($mode) != 1)
            throw new Erebot_InvalidValueException(
                $translator->gettext('Invalid mode'));

        if (!isset($this->_supported['PREFIX']))
            throw new Erebot_NotFoundException($translator->gettext(
                'No mapping for prefixes'));

        $ok = preg_match(self::PATTERN_PREFIX,
            $this->_supported['PREFIX'], $matches);

        if ($ok) {
            $pos = strpos($matches[1], $mode);
            if ($pos !== FALSE && strlen($matches[2]) > $pos)
                return $matches[2][$pos];
        }

        throw new Erebot_NotFoundException(
            $translator->gettext('No such mode'));
    }

    public function getChanModeForPrefix($prefix)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_string($prefix) || strlen($prefix) != 1)
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid prefix'));

        if (!isset($this->_supported['PREFIX']))
            throw new Erebot_NotFoundException($translator->gettext(
                'No mapping for prefixes'));

        $ok = preg_match(
            self::PATTERN_PREFIX,
            $this->_supported['PREFIX'],
            $matches
        );
        if ($ok) {
            $pos = strpos($matches[2], $prefix);
            if ($pos !== FALSE && strlen($matches[1]) > $pos)
                return $matches[1][$pos];
        }

        throw new Erebot_NotFoundException(
            $translator->gettext('No such prefix'));
    }

    public function qualifyChannelMode($mode)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_string($mode) || strlen($mode) != 1)
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid mode'));

        if (!isset($this->_supported['CHANMODES']) ||
            !is_array($this->_supported['CHANMODES']))
            throw new Erebot_NotFoundException('No such mode');

        $type = self::MODE_TYPE_A;
        foreach ($this->_supported['CHANMODES'] as &$modes) {
            if ($type > self::MODE_TYPE_D)  // Modes after type 4 are reserved
                break;                      // for future extensions.

            if (strpos($modes, $mode) !== FALSE)
                return $type;
            $type++;
        }
        unset($modes);
        throw new Erebot_NotFoundException(
            $translator->gettext('No such mode'));
    }

    public function getMaxTargets($cmd)
    {
        if (!is_string($cmd)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException(
                $translator->gettext('Invalid command'));
        }

        $cmd = strtoupper($cmd);
        if (isset($this->_supported['TARGMAX'][$cmd])) {
            if ($this->_supported['TARGMAX'][$cmd] == '')
                return -1;

            if (ctype_digit($this->_supported['TARGMAX'][$cmd]))
                return (int) $this->_supported['TARGMAX'][$cmd];
        }

        else if (isset($this->_supported['MAXTARGETS']) &&
                ctype_digit($this->_supported['MAXTARGETS']))
            return (int) $this->_supported['MAXTARGETS'];

        return -1;
    }

    public function getMaxVariableModes()
    {
        if (isset($this->_supported['MODES'])) {
            if ($this->_supported['MODES'] == '')
                return -1;

            if (ctype_digit($this->_supported['MODES']))
                return (int) $this->_supported['MODES'];
        }

        return 3;
    }

    public function getMaxListModes($modes)
    {
        if (is_string($modes))
            $modes = str_split($modes);

        if (!is_array($modes)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException($translator->gettext(
                'Invalid modes'));
        }

        if (!isset($this->_supported['MAXLIST']))
            return $this->getMaxVariableModes();

        foreach ($this->_supported['MAXLIST'] as $maxs => $limit) {
            $maxs = str_split($maxs);
            if (!count(array_diff($modes, $maxs)) && ctype_digit($limit))
                return (int) $limit;
        }
        return $this->getMaxVariableModes();
    }

    public function getMaxParams()
    {
        if (isset($this->_supported['MAXPARA']) &&
            ctype_digit($this->_supported['MAXPARA']))
            return (int) $this->_supported['MAXPARA'];
        return 12;
    }

    /**
     * Indicates whether this server support SSL connections.
     *
     * \retval dict
     *      An associative array whose keys are the IP addresses
     *      and whose values are the port where SSL support is
     *      enabled.
     *
     * \throw Erebot_InvalidValueException
     *      The data received from the IRC server was invalid.
     *
     * \throw Erebot_NotFoundException
     *      No information could be retrieved indicating whether
     *      this IRC server supports SSL connections or not.
     *
     * \note
     *      When SSL is supported on all IPs for a given port,
     *      the IP (the key) is defined as "*".
     */
    public function getSSL()
    {
        $translator = $this->getTranslator(NULL);
        if (isset($this->_supported['SSL'])) {
            // Received "SSL=", so assume no SSL support.
            if ($this->_supported['SSL'] === TRUE)
                return array();

            if (is_string($this->_supported['SSL'])) {
                list($key, $val) = explode(':', $this->_supported['SSL']);
                $ssl = array($key => $val);
            }
            else if (is_array($this->_supported['SSL']))
                $ssl = $this->_supported['SSL'];
            else
                throw new Erebot_InvalidValueException(
                    $translator->gettext('Invalid data received'));;

            $result = array();
            foreach ($ssl as $ip => $val) {
                $port = (int) $val;
                if (!ctype_digit($val) || $port <= 0 || $port > 65535)
                    throw new Erebot_InvalidValueException(
                        $translator->gettext('Not a valid port'));;
                $result[$ip] = $port;
            }
            return $result;
        }

        throw new Erebot_NotFoundException($translator->gettext(
            'No SSL information available'));
    }

    public function getIdLength($prefix)
    {
        $translator = $this->getTranslator(NULL);
        if (!is_string($prefix) || strlen($prefix) != 1)
            throw new Erebot_InvalidValueException(
                $translator->gettext('Bad prefix'));

        if (isset($this->_supported['IDCHAN'][$prefix]) &&
            ctype_digit($this->_supported['IDCHAN'][$prefix]))
            return (int) $this->_supported['IDCHAN'][$prefix];

        if (isset($this->_supported['CHIDLEN']) &&
            ctype_digit($this->_supported['CHIDLEN']))
            return (int) $this->_supported['CHIDLEN'];

        throw new Erebot_NotFoundException($translator->gettext(
            'No safe channels on this server'));
    }

    public function supportsStandard($standard)
    {
        if (!is_string($standard)) {
            $translator = $this->getTranslator(NULL);
            throw new Erebot_InvalidValueException($translator->gettext(
                'Bad standard name'));
        }

        if (isset($this->_supported['STD'])) {
            $standards = array();

            if (is_string($this->_supported['STD']))
                $standards[] = $this->_supported['STD'];
            else if (is_array($this->_supported['STD']))
                $standards = $this->_supported['STD'];

            foreach ($standards as &$std) {
                if (!strcasecmp($std, $standard))
                    return TRUE;
            }
            unset($std);
        }

        if (!strcasecmp($standard, 'rfc2812') &&
            isset($this->_supported['RFC2812']))
            return TRUE;

        return FALSE;
    }

    public function getExtendedBanPrefix()
    {
        if (is_array($this->_supported['EXTBAN']) &&
            isset($this->_supported['EXTBAN'][0]) &&
            strlen($this->_supported['EXTBAN'][0]) == 1) {
            return $this->_supported['EXTBAN'][0];
        }

        $translator = $this->getTranslator(NULL);
        throw new Erebot_NotFoundException($translator->gettext(
            'Extended bans not supported on this server'));
    }

    public function getExtendedBanModes()
    {
        if (is_array($this->_supported['EXTBAN']) &&
            isset($this->_supported['EXTBAN'][1])) {
            return $this->_supported['EXTBAN'][1];
        }

        $translator = $this->getTranslator(NULL);
        throw new Erebot_NotFoundException($translator->gettext(
            'Extended bans not supported on this server'));
    }
}

