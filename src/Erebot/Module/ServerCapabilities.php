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

/**
 * \brief
 *      A module that can determine what an IRC server
 *      is capable of.
 */
class   Erebot_Module_ServerCapabilities
extends Erebot_Module_Base
{
    /// Refers to the list of bans.
    const LIST_BANS         = 0;

    /// Refers to the list of silenced persons.
    const LIST_SILENCES     = 1;

    /// Refers to the list of ban exceptions.
    const LIST_EXCEPTS      = 2;

    /// Refers to the list of invitations.
    const LIST_INVITES      = 3;

    /// Refers to the list of watched persons.
    const LIST_WATCHES      = 4;

    /// Refers to channel names.
    const TEXT_CHAN_NAME    = 0;

    /// Refers to nicknames.
    const TEXT_NICKNAME     = 1;

    /// Refers to channel topics.
    const TEXT_TOPIC        = 2;

    /// Refers to kick messages.
    const TEXT_KICK         = 3;

    /// Refers to away messages.
    const TEXT_AWAY         = 4;

    /// Refers to modes that add or remove a nick or address to a list.
    const MODE_TYPE_A       = 0;

    /// Refers to modes that change a setting and always have a parameter.
    const MODE_TYPE_B       = 1;

    /**
     * Refers to modes that change a setting
     * and only have a parameter when set.
     */
    const MODE_TYPE_C       = 2;

    /// Refers to modes that change a setting and never have a parameter.
    const MODE_TYPE_D       = 3;

    /// ELIST mode for a mask search.
    const ELIST_MASK        = 'M';

    /// ELIST mode for a negative mask search.
    const ELIST_NEG_MASK    = 'N';

    /// ELIST mode for usercount search.
    const ELIST_USERS       = 'U';

    /// ELIST mode for creation time search.
    const ELIST_CREATION    = 'C';

    /// ELIST mode for topic search.
    const ELIST_TOPIC       = 'T';

    /// Pattern for a prefix-to-mode mapping.
    const PATTERN_PREFIX    = '/^\\(([^\\)]+)\\)(.*)$/';


    /// Modes/commands/exteions/options supported by this IRC server.
    protected $_supported;

    /// Whether server capabilities have been parsed yet or not.
    protected $_parsed;


    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     */
    public function _reload($flags)
    {
        if ($this->_channel !== NULL)
            return;

        if ($flags & self::RELOAD_INIT) {
            $this->_supported   = array();
            $this->_parsed      = FALSE;
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handler = new Erebot_RawHandler(
                new Erebot_Callable(array($this, 'handleRaw')),
                $this->getRawRef('RPL_ISUPPORT')
            );
            $this->_connection->addRawHandler($handler);

            $handler = new Erebot_RawHandler(
                new Erebot_Callable(array($this, 'handleRaw')),
                $this->getRawRef('RPL_LUSERCLIENT')
            );
            $this->_connection->addRawHandler($handler);
        }
    }

    /**
     * Handles raw numeric events.
     *
     * \param Erebot_Interface_RawHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Raw $raw
     *      The raw event to handle.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleRaw(
        Erebot_Interface_RawHandler $handler,
        Erebot_Interface_Event_Raw  $raw
    )
    {
        $rawCode    = $raw->getRaw();
        $loader     = $this->_connection->getRawProfileLoader();
        if ($rawCode == $loader->getRawByName('RPL_LUSERCLIENT') &&
            !$this->_parsed) {
            $this->_parsed = TRUE;
            $event = new Erebot_Event_ServerCapabilities(
                $this->_connection,
                $this
            );
            $this->_connection->dispatch($event);
            return;
        }

        if ($rawCode != $loader->getRawByName('RPL_ISUPPORT'))
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

    /**
     * Parses a token from the 005 raw numeric
     * event.
     *
     * \param string $token
     *      A token from the 005 raw event.
     *
     * \retval array
     *      Result of the parsing.
     */
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
     * Whether the server can send full the user!ident\@host
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

    /**
     * Indicates whether the server gives extra penalty
     * to some commands instead of the normal 2 seconds
     * per message and 1 second for every 120 bytes in
     * a message.
     *
     * \retval bool
     *      TRUE if the server gives extra penalty for
     *      certain commands, FALSE otherwise.
     */
    public function hasExtraPenalty()
    {
        return isset($this->_supported['PENALTY']);
    }

    /**
     * Indicates whether the server may change the bot's
     * nickname on its own.
     *
     * \retval bool
     *      TRUE if the server may choose to change a user's
     *      nickname at its own discretion, FALSE otherwise.
     */
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
            $fmt = $this->getFormatter(NULL);
            throw new Erebot_InvalidValueException(
                $fmt->_('Not a valid command name')
            );
        }
        $cmdName = strtoupper($cmdName);

        // RFC 2812
        $rfcFeatures = array(
            'AWAY',
            'REHASH',
            'DIE',
            'RESTART',
            'SUMMON',
            'USERS',
            'WALLOPS',
            'USERHOST',
            'ISON',
        );

        if ($this->supportsStandard('RFC2812') &&
            in_array($cmdName, $rfcFeatures))
            return TRUE;

        if (isset($this->_supported[$cmdName]))
            return TRUE;

        if (isset($this->_supported['CMDS']) &&
            in_array($cmdName, $this->_supported['CMDS']))
            return TRUE;
        return FALSE;
    }

    /**
     * Indicates whether replies to a LIST command
     * will be sent in multiple iterations to avoid
     * the send queue to overflow.
     *
     * \retval bool
     *      TRUE if replies to LIST commands may be
     *      split in several messages, FALSE otherwise.
     */
    public function hasSafeList()
    {
        return isset($this->_supported['SAFELIST']);
    }

    /**
     * Indicates whether the server may deny requests
     * to send channel lists until the bot has been
     * connected for long enough.
     *
     * \retval bool
     *      TRUE if the server may deny access to channel
     *      lists until a certain amount of time has been
     *      spent connected, FALSE otherwise.
     */
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

    /**
     * Indicates whether a message can be sent in a channel
     * so that only users with a specific status will receive it.
     *
     * \param string $status
     *      A status prefix for which this ability must be tested.
     *
     * \retval bool
     *      Indicates that the given status prefix
     *
     * \note
     *      Nowadays, most IRC servers support at least this
     *      features for the '+', '%' & '@' status prefixes,
     *      meant to send messages to voices only, halfops
     *      only & operators only (respectively).
     *
     * \note
     *      Depending on the IRC server, the exact command to use
     *      to send messages to users with a specific status may
     *      vary. In particular, on some servers you'll need to
     *      use dedicated commands, like WALLCHOPS or WALLVOICES.
     */
    public function hasStatusMsg($status)
    {
        if (!is_string($status) || strlen($status) != 1) {
            $fmt = $this->getFormatter(NULL);
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid status')
            );
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
     *      Potential channel name to test.
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
        if (!Erebot_Utils::stringifiable($chan)) {
            $fmt = $this->getFormatter(NULL);
            throw new Erebot_InvalidValueException(
                $fmt->_('Bad channel name')
            );
        }

        $chan = (string) $chan;
        if (!strlen($chan))
            return FALSE;

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

    /**
     * Returns the maximum number of entries a list may contain.
     *
     * \param opaque $list
     *      One of the LIST_* constants, representing the type
     *      of list for which this information must be retrieved.
     *
     * \retval mixed
     *      Returns the number of entries this type of list may
     *      contain or NULL if no maximum has been defined.
     *
     * \throw Erebot_InvalidValueException
     *      The value passed to $list does not represent a valid
     *      list type.
     *
     * \note
     *      Even if the server does not specify a maximum number
     *      of entries for some list, you should <b>always</b>
     *      assume that the server has an implicit value for it,
     *      which is usually not that high (like 30 entries or so).
     *
     * \note
     *      On some servers, several lists are managed together
     *      hence the value returned by this method represents
     *      the maximum number of entries these shared lists
     *      may occupy and not the individual capacity of these
     *      lists.
     */
    public function getMaxListSize($list)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_int($list)) {
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid list type')
            );
        }

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
                throw new Erebot_InvalidValueException(
                    $fmt->_('Invalid list type')
                );
        }
    }

    /**
     * Returns the maximum number of channels of a given type
     * that you may join simultaneously.
     *
     * \param string $chanPrefix
     *      The type of channel to query, given by its prefix
     *      (eg. "#" or "&").
     *
     * \retval int
     *      The number of channels of this type that you may
     *      be on simultaneously or -1 if there is no such
     *      limit.
     *
     * \throw Erebot_InvalidValueException
     *      The given $chanPrefix does not refer to a valid
     *      channel type.
     *
     * \note
     *      Even if this method returns -1 for a given type,
     *      you should <b>always</b> assume that the server
     *      uses an implicit limit (sometimes as low as 10).
     */
    public function getChanLimit($chanPrefix)
    {
        if (!$this->isChannel($chanPrefix))
            throw new Erebot_InvalidValueException('Invalid prefix');

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

    /**
     * Returns the maximum size messages/entities of a given type
     * may occupy.
     *
     * \param opaque $type
     *      The type of message/entity to test, expressed using one
     *      of the TEXT_* constants of this class.
     *
     * \retval int
     *      The maximum size for a message/entity of type $type
     *      or -1 if there is no limit (or the limit is unknown).
     *
     * \throw Erebot_InvalidValueException
     *      The given $type does not refer to a valid
     *      type of message/entity.
     *
     * \note
     *      Even if this method returns -1, you should
     *      <b>always</b> assume that an implicit limit
     *      exists (whose value still depends on the type).
     *      For example, the original Request for Comments
     *      on IRC (RFC 1459) specifies that nicknames
     *      may not exceed 9 characters in length.
     */
    public function getMaxTextLen($type)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_int($type))
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid text type')
            );

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
                throw new Erebot_InvalidValueException(
                    $fmt->_('Invalid text type')
                );
        }
        return -1;
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

        $fmt = $this->getFormatter(NULL);
        throw new Erebot_NotFoundException(
            $fmt->_('No charset specified')
        );
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
     *      The name returned by this method is purely informative.
     *      The server is free to send any name it chooses.
     *      Don't rely on this to implement security checks!
     */
    public function getNetworkName()
    {
        if (isset($this->_supported['NETWORK']) &&
            is_string($this->_supported['NETWORK']))
            return $this->_supported['NETWORK'];

        throw new Erebot_NotFoundException(
            $fmt->_('No network declared')
        );
    }

    /**
     * Returns the channel mode to use to retrieve
     * the content of a channel list of a certain type.
     *
     * \param opaque $list
     *      The type of list to query, expressed using
     *      one of the LIST_* constants of this class.
     *
     * \retval string
     *      The channel mode to query to retrieve the
     *      content of the list represented by $list.
     *
     * \throw Erebot_InvalidValueException
     *      There is no such type of list.
     *
     * \throw Erebot_NotFoundException
     *      The given type of list is not available
     *      on that particular IRC server.
     */
    public function getChanListMode($list)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_int($list))
            throw new Erebot_InvalidValueException(
                $fmt->_('Bad channel list ID')
            );

        switch ($list) {
            case self::LIST_BANS:
                return 'b';

            case self::LIST_EXCEPTS:
                if (!isset($this->_supported['EXCEPTS']))
                    throw new Erebot_NotFoundException(
                        $fmt->_(
                            'Excepts are not available on this server'
                        )
                    );

                if ($this->_supported['EXCEPTS'] === TRUE)
                    return 'e';
                return $this->_supported['EXCEPTS'];
                break;

            case self::LIST_INVITES:
                if (!isset($this->_supported['INVEX']))
                    throw new Erebot_NotFoundException(
                        $fmt->_(
                            'Invites are not available on this server'
                        )
                    );

                if ($this->_supported['INVEX'] === TRUE)
                    return 'I';
                return $this->_supported['INVEX'];
                break;

            default:
                throw new Erebot_InvalidValueException(
                    $fmt->_('Invalid channel list ID')
                );
        }
    }

    /**
     * Indicates whether the given mode is a valid
     * channel privilege (such as "o" or "v") or not.
     *
     * \param string $mode
     *      The channel mode to test.
     *
     * \retval bool
     *      TRUE if the given mode can be used as a
     *      valid channel privilege, FALSE otherwise.
     */
    public function isChannelPrivilege($mode)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_string($mode) || strlen($mode) != 1)
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid mode')
            );

        if (!isset($this->_supported['PREFIX']))
            // Default prefixes based on RFC 1459.
            $prefixes = '(ov)@+';
        else
            $prefixes = $this->_supported['PREFIX'];

        $ok = preg_match(
            self::PATTERN_PREFIX,
            $prefixes,
            $matches
        );

        if ($ok)
            return (strpos($matches[1], $mode) !== FALSE);
        return FALSE;
    }

    /**
     * Returns the prefix associated with a given
     * channel mode representing a specific status
     * (eg. '+' for voices [v] and '@' for operators [o]).
     *
     * \param string $mode
     *      The channel mode for which the corresponding
     *      prefix must be returned.
     *
     * \retval string
     *      The prefix corresponding to that mode.
     *
     * \throw Erebot_InvalidValueException
     *      The given $mode is not valid.
     *
     * \throw Erebot_NotFoundException
     *      The given $mode does not refer to a channel status.
     *
     * \see
     *      Erebot_Module_ServerCapabilities::getChanModeForPrefix()
     *      does the opposite translation.
     */
    public function getChanPrefixForMode($mode)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_string($mode) || strlen($mode) != 1)
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid mode')
            );

        if (!isset($this->_supported['PREFIX']))
            // Default prefixes based on RFC 1459.
            $prefixes = '(ov)@+';
        else
            $prefixes = $this->_supported['PREFIX'];

        $ok = preg_match(
            self::PATTERN_PREFIX,
            $prefixes,
            $matches
        );

        if ($ok) {
            $pos = strpos($matches[1], $mode);
            if ($pos !== FALSE && strlen($matches[2]) > $pos)
                return $matches[2][$pos];
        }

        throw new Erebot_NotFoundException(
            $fmt->_('No such mode')
        );
    }

    /**
     * Returns the channel mode associated with a given prefix
     * (eg. 'v' for voices [+] and 'o' for operators [@]).
     *
     * \param string $prefix
     *      The prefix for which the corresponding
     *      channel mode must be returned.
     *
     * \retval string
     *      The mode corresponding to that prefix.
     *
     * \throw Erebot_InvalidValueException
     *      The given $prefix is not valid.
     *
     * \throw Erebot_NotFoundException
     *      The given $prefix does not refer to a channel status.
     *
     * \see
     *      Erebot_Module_ServerCapabilities::getChanPrefixForMode()
     *      does the opposite translation.
     */
    public function getChanModeForPrefix($prefix)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_string($prefix) || strlen($prefix) != 1)
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid prefix')
            );

        if (!isset($this->_supported['PREFIX']))
            // Default prefixes based on RFC 1459.
            $prefixes = '(ov)@+';
        else
            $prefixes = $this->_supported['PREFIX'];

        $ok = preg_match(
            self::PATTERN_PREFIX,
            $prefixes,
            $matches
        );
        if ($ok) {
            $pos = strpos($matches[2], $prefix);
            if ($pos !== FALSE && strlen($matches[1]) > $pos)
                return $matches[1][$pos];
        }

        throw new Erebot_NotFoundException(
            $fmt->_('No such prefix')
        );
    }

    /**
     * Returns the type of mode a given channel mode belongs to,
     * using the classification method defined in RFC 1459.
     *
     * \param string $mode
     *      The channel mode to qualify.
     *
     * \retval opaque
     *      One of the MODE_TYPE_* constants defined
     *      in this class, indicating whether the given
     *      $mode is of type A, B, C or D.
     *
     * \throw Erebot_InvalidValueException
     *      The given parameter does not refer to a valid
     *      channel mode.
     *
     * \throw Erebot_NotFoundException
     *      The given $mode does not exist on this IRC server.
     */
    public function qualifyChannelMode($mode)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_string($mode) || strlen($mode) != 1)
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid mode')
            );

        if (!isset($this->_supported['CHANMODES']) ||
            !is_array($this->_supported['CHANMODES']))
            throw new Erebot_NotFoundException('No such mode');

        $type = self::MODE_TYPE_A;
        foreach ($this->_supported['CHANMODES'] as $modes) {
            if ($type > self::MODE_TYPE_D)  // Modes after type 4 are reserved
                break;                      // for future extensions.

            if (strpos($modes, $mode) !== FALSE)
                return $type;
            $type++;
        }
        throw new Erebot_NotFoundException(
            $fmt->_('No such mode')
        );
    }

    /**
     * Returns the maximum number of targets (nickname or channel)
     * a given command may accept.
     *
     * \param string $cmd
     *      The command to query.
     *
     * \retval int
     *      The maximum number of targets for that command
     *      or -1 if there is no limit or it is unknown.
     *
     * \throw Erebot_InvalidValueException
     *      $cmd does not refer to a valid IRC command.
     *
     * \note
     *      Even if this method returns -1, you should <b>always</b>
     *      assumre that the IRC server uses an implicit limit.
     *
     * \note
     *      Even if the server accepts a large number of simultaneous
     *      targets, the maximum size of the command must still comply
     *      with other limits defined by the server and the various
     *      standards (eg. RFC 1459 imposes a maximum length of 1024
     *      bytes for every message sent, including the trailing CR LF
     *      sequence).
     */
    public function getMaxTargets($cmd)
    {
        if (!is_string($cmd)) {
            $fmt = $this->getFormatter(NULL);
            throw new Erebot_InvalidValueException(
                $fmt->_('Invalid command')
            );
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

    /**
     * Returns the maximum number of channel modes that may be
     * changed at once.
     *
     * \retval int
     *      The maximum number of channel modes of any type
     *      that can be changed at once.
     *
     * \note
     *      For historical reasons, a value of 3 is assumed
     *      for IRC servers that do not specify a limit.
     */
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

    /**
     * Returns the maximum number of parameters that may
     * be passed to any command.
     *
     * \retval int
     *      Maximum number of parameters to any command.
     *
     * \note
     *      This value will generally be 12, which is
     *      what RFC 1459 defines, but may also be set
     *      to an higher value as IRC servers are now
     *      capable of processing more complex messages.
     */
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
        $fmt = $this->getFormatter(NULL);
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
                    $fmt->_('Invalid data received')
                );

            $result = array();
            foreach ($ssl as $ip => $val) {
                $port = (int) $val;
                if (!ctype_digit($val) || $port <= 0 || $port > 65535)
                    throw new Erebot_InvalidValueException(
                        $fmt->_('Not a valid port')
                    );
                $result[$ip] = $port;
            }
            return $result;
        }

        throw new Erebot_NotFoundException(
            $fmt->_('No SSL information available')
        );
    }

    /**
     * Returns the length of the "id" portion of "safe" channels
     * of a given type.
     *
     * \param string $prefix
     *      The type of channel, represented by its prefix.
     *
     * \retval int
     *      The length of the "id" portion of safe channels.
     *
     * \throw Erebot_InvalidValueException
     *      The given $prefix does not represent a valid channel type.
     *
     * \throw Erebot_NotFoundException
     *      Safe channels are not supported by this IRC server.
     */
    public function getIdLength($prefix)
    {
        $fmt = $this->getFormatter(NULL);
        if (!is_string($prefix) || strlen($prefix) != 1)
            throw new Erebot_InvalidValueException(
                $fmt->_('Bad prefix')
            );

        if (isset($this->_supported['IDCHAN'][$prefix]) &&
            ctype_digit($this->_supported['IDCHAN'][$prefix]))
            return (int) $this->_supported['IDCHAN'][$prefix];

        if (isset($this->_supported['CHIDLEN']) &&
            ctype_digit($this->_supported['CHIDLEN']))
            return (int) $this->_supported['CHIDLEN'];

        throw new Erebot_NotFoundException(
            $fmt->_(
                'Safe channels are not available on this server'
            )
        );
    }

    /**
     * Indicates whether this IRC server supports a specific
     * standard.
     *
     * \param string $standard
     *      The name of the standard for which support must
     *      be tested.
     *
     * \retval bool
     *      TRUE if the IRC server supports that $standard,
     *      FALSE otherwise.
     *
     * \throw Erebot_InvalidValueException
     *      The given $standard does not a refer to a valid
     *      standard.
     *
     * \note
     *      Values returned by this method are purely
     *      informational. An IRC server may choose to not
     *      advise support for standards it recognized.
     *      It may also advise support for a standard while
     *      it only partially implements it.
     */
    public function supportsStandard($standard)
    {
        if (!is_string($standard)) {
            $fmt = $this->getFormatter(NULL);
            throw new Erebot_InvalidValueException(
                $fmt->_('Bad standard name')
            );
        }

        if (isset($this->_supported['STD'])) {
            $standards = array();

            if (is_string($this->_supported['STD']))
                $standards[] = $this->_supported['STD'];
            else if (is_array($this->_supported['STD']))
                $standards = $this->_supported['STD'];

            foreach ($standards as $std) {
                if (!strcasecmp($std, $standard))
                    return TRUE;
            }
        }

        if (!strcasecmp($standard, 'rfc2812') &&
            isset($this->_supported['RFC2812']))
            return TRUE;

        return FALSE;
    }

    /**
     * Returns the prefix used to modify or query the list
     * of the extended bans.
     *
     * \retval string
     *      The prefix to use to manipulate extended bans.
     *
     * \throw Erebot_NotFoundException
     *      This IRC server does not support extended bans.
     */
    public function getExtendedBanPrefix()
    {
        if (is_array($this->_supported['EXTBAN']) &&
            isset($this->_supported['EXTBAN'][0]) &&
            strlen($this->_supported['EXTBAN'][0]) == 1) {
            return $this->_supported['EXTBAN'][0];
        }

        $fmt = $this->getFormatter(NULL);
        throw new Erebot_NotFoundException(
            $fmt->_(
                'Extended bans are not supported on this server'
            )
        );
    }

    /**
     * Returns the list of extended bans implemented
     * by this IRC server.
     *
     * \retval list
     *      List of extended bans supported by this server.
     *
     * \throw Erebot_NotFoundException
     *      This IRC server does not support extended bans.
     */
    public function getExtendedBanModes()
    {
        if (is_array($this->_supported['EXTBAN']) &&
            isset($this->_supported['EXTBAN'][1])) {
            return str_split($this->_supported['EXTBAN'][1]);
        }

        $fmt = $this->getFormatter(NULL);
        throw new Erebot_NotFoundException(
            $fmt->_('Extended bans are not supported on this server')
        );
    }
}

