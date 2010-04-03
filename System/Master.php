<?php
/**
 *	Master class for OUTRAGEbot
 *
 *	This class deals with all the interaction with plugins, how the bot acts, etc.
 *	This class also contains all of the commands, etc.
 *
 *	In this version, about half of the functions have been renamed, in order
 *	to be easier to remember, for example. That means, any
 *
 *	Note: In this documentation, there are some psuedo types that are used to describe
 *	certain arguments.
 *
 *	- callback<code>
 *	$cCallback = array($this, "Function"); // Different class instance
 *	$cCallback = "callLocalFunction";      // Only available in plugins
 *	</code>It is used for binds and timers.
 *
 *	@package OUTRAGEbot
 *	@copyright David Weston (c) 2010 -> http://www.typefish.co.uk/licences/
 *	@author David Weston <westie@typefish.co.uk>
 *	@version 1.0.1
 */
 

/* The real code. Woohoo */
class Master
{
	/**
	 *	@ignore
	 */
	public $sBotGroup;
	
	
	/**
	 *	@ignore
	 */
	public $oPlugins;
	
	
	/**
	 *	@ignore
	 */
	public $aHandlers = array();
	
	
	/**
	 *	@ignore
	 */
	public $MasterPresent = false;
		
	
	/**
	 *	@ignore
	 */
	public $aBotObjects = array();
	
	
	/**
	 *	@ignore
	 */
	public $oModes;
	
	
	/**
	 *	@ignore
	 */
	public $iBotIndex = 0;
	
	
	/**
	 *	@ignore
	 */
	public $oConfig;
	
	
	/**
	 *	Contains the instance of the current bot.
	 *	@var Socket
	 */
	public $oCurrentBot;
	
	
	/**
	 *	Contains the string/message that is sent to the bot
	 *	@var string
	 */
	public $sCurrentChunk;
	
	
	/**
	 *	Constructor for class 'Master'
	 *
	 *	@ignore
	 */
	public function __construct($sKey, &$oConfig)
	{
		$this->oConfig = $oConfig;
		$this->sBotGroup = $sKey;
		$this->oPlugins = new stdClass();
		$this->oModes = new stdClass();
		
		Control::$aStack[$this->sBotGroup] = array();

		echo PHP_EOL." Creating '{$this->oConfig->Network['name']}' at {$this->oConfig->Network['host']}:{$this->oConfig->Network['port']}".PHP_EOL;
		
		foreach($this->oConfig->Bots as $aOption)
		{	
			$this->_addChild($aOption['nickname'], $aOption);
		}
		
		foreach(explode(',', $this->oConfig->Network['plugins']) as $sPlugin)
		{
			$sPlugin = trim($sPlugin);
			$this->addPlugin($sPlugin);
		}
		
		/* The uncool stuff. This does mean that yeah, you can this in the configs. */
		if(!isset($this->oConfig->Network['delimiter']))
		{
			$this->oConfig->Network['delimiter'] = "!";
		}
		
		if(!isset($this->oConfig->Network['rotation']))
		{
			$this->oConfig->Network['rotation'] = SEND_DEF;
		}
		
		if(!isset($this->oConfig->Network['quitmsg']))
		{
			$this->oConfig->Network['quitmsg'] = "OUTRAGEbot is going to bed :(";
		}
		
		if(!isset($this->oConfig->Network['version']))
		{
			$this->oConfig->Network['version'] = "OUTRAGEbot ".BOT_VERSION." (rel. ".BOT_RELDATE."); David Weston; http://outrage.typefish.co.uk";
		}
		
		foreach(explode(',', $this->oConfig->Network['owners']) as $sAddr)
		{
			$sAddr = trim($sAddr);
			$this->oConfig->Network['_owners'][] = $sAddr;
		}
	}
	
	
	/**
	 *	Destructor for class 'Master'
	 *
	 *	@ignore
	 */
	public function _onDestruct()
	{
		foreach($this->oPlugins as $sReference => $oPlugin)
		{
			call_user_func(array($this->oPlugins->$sReference, "onDestruct"));
			unset($this->oPlugins->$sReference);
		}
		
		foreach($this->aBotObjects as $iReference => $oBotObject)
		{
			$oBotObject->destructBot();
			unset($this->aBotObjects[$iReference]);
		}
		
		unset($this->oModes);
		unset($this->oConfig);
	}
	
	
	/**
	 *	Destructor for class 'Master'
	 *
	 *	@ignore
	 */
	public function __destruct()
	{
		$this->_onDestruct();
	}
	
	
	/**
	 *	Loops the bot and its slaves.
	 *
	 *	@ignore
	 */
	public function Loop()
	{
		foreach($this->aBotObjects as $oClones)
		{	
			$oClones->Input();
			
			if($oClones->isClone() == false)
			{
				$this->invokeEvent("onTick");
			}
		}
	}
	
	
	/**
	 *	The backend of creating a child. Why oh why did I make it so complicated?
	 *
	 *	@ignore
	 *	@param string $sChild Child reference.
	 *	@param array $aDetails Details of the child.
	 *	@return bool true on success.
	 */
	private function _addChild($sChild, $aDetails)
	{
		if($this->doesChildExist($sChild))
		{
			return false;
		}
		
		$aDetails['slave'] = $this->MasterPresent;
		
		if($this->MasterPresent == false)
		{
			$this->MasterPresent = true;
			$this->MasterReference = $sChild;
		}
		
		$aDetails['timewait'] = 1;
		$aDetails['loadtime'] = (time() + $aDetails['timewait']);
		
		$this->aBotObjects[] = new Socket($this, $sChild, $aDetails);
		return true;
	}
	
	
	/**
	 *	This function creates a child. A child is an instance of the Socket,
	 *	basically an IRC client.
	 *
	 *	<code>$this->addChild("bot4", "OUTRAGEbot|4", "outrage", "OUTRAGEbot");</code> 
	 *
	 *	@param string $sChild Child reference.
	 *	@param string $sNickname The child's nickname.
	 *	@param string $sUsername The child's username.
	 *	@param string $sRealname The child's real name.
	 *	@return bool true on success.
	 */
	public function addChild($sChild, $sNickname, $sUsername = false, $sRealname = false)
	{
		$aDetails = array
		(
			'nickname' => $sNickname,
			'username' => ($sUsername == false ? $sChild : $sUsername),
			'realname' => ($sRealname == false ? $sNickname : $sRealname),
			'altnick' => $sNickname.rand(0, 10),
			'reactevent' => 'false',
		);
		
		return $this->_addChild($sChild, $aDetails);
	}
	
	
	/**
	 *	Returns a list of all the children that the bot has.
	 *
	 *	<code>$aChildren = $this->getChildren();</code>
	 *
	 *	@return array Array of children's names.
	 */
	public function getChildren()
	{
		$aReturn = array();
		
		foreach($this->aBotObjects as $iReference => $oChild)
		{
			$aReturn[$iReference] = $oChild->sChild;
		}
		
		return $aReturn;
	}
	
	
	/**
	 *	Returns an object of a child from its reference.
	 *
	 *	<code>
	 *	$oChild = $this->getChildObject("OUTRAGEbot");
	 *	$oChild->Output("PRIVMSG #OUTRAGEbot :Hello from the raw!");</code>
	 *
	 *	@param string $sChild Child reference.
	 *	@return Socket Class of the socket child.
	 */
	public function getChildObject($sChild)
	{
		$aReturn = array();
		
		foreach($this->aBotObjects as $iReference => $oChild)
		{
			if($sChild == $oChild->sChild)
			{
				return $oChild;
			}
		}
		
		return null;
	}
	
	
	/**
	 *	This function renames a child by its reference. The reference is (in most cases)
	 *	the bot's original name. Look in the configuration for more details.
	 *
	 *	<code>$this->setNickname('OUTRAGEbot', 'someRandomBotNameHere');</code>
	 *
	 *	@param string $sChild Child reference.
	 *	@param string $sNewNick New nickname of the child.
	 */
	public function setNickname($sChild, $sNewNick)
	{
		if(($oChild = $this->getChildObject($sChild)) === null)
		{
			return false;
		}
		
		$oChild->setNickname($sNewNick);
	}
	
	
	/**
	 *	Removes a child from this group.
	 *	Please note that you cannot remove the master. That would just be pointless.
	 *
	 *	<code>$this->removeChild('bot4', 'bai bai!');
	 *
	 *	@param string $sChild Child reference.
	 *	@param string $sReason Reason for quitting channel.
	 *	@return bool true on success.
	 */
	public function removeChild($sChild, $sReason = false)
	{
		foreach($this->aBotObjects as $iReference => $oChild)
		{
			if($oChild->sChild == $sChild)
			{
				if($iReference == 0)
				{
					return false;
				}
				
				$oChild->destructBot($sReason);
				unset($this->aBotObjects[$iReference]);				
				$this->aBotObjects = array_values($this->aBotObjects);
				
				return true;
			}
		}
		
		return false;
	}
	
	
	/**
	 *	Checks if you have to pay the government alimony. (It really check
	 *	if the child exists.) It's good that someone reads the source,
	 *	otherwise you wouldn't find this.
	 *
	 *	@param string $sChild Child name.
	 *	@return bool true on success.
	 *	@ignore
	 *	@see Master::doesChildExist()
	 */
	public function doIHaveToPayAlimony($sChild)
	{
		return $this->doesChildExist($sChild);
	}
	
	
	
	/**
	 *	Checks if a child exists. Note that the child name is not necessarily the 
	 *	IRC nick of the bot, but in most cases it is.
	 *
	 *	<code>
	 *	if($this->doesChildExist('bot4') == true)
	 *	{
	 *		...
	 *	}</code>
	 *
	 *	@param string $sChild Child name.
	 *	@return bool true on success.
	 */
	public function doesChildExist($sChild)
	{
		foreach($this->aBotObjects as $oChild)
		{
			if($oChild->sChild == $sChild)
			{
				return true;
			}
		}
		
		return false;
	}


	/**
	 *	This function reconnects a IRC child. This is useful in cases where IRC bots
	 *	have to physically disconnect from the network in order to work.
	 *
	 *	<code>$this->resetChild('bot4', 'Looks like I need to reconnect to the network!');</code>
	 *
	 *	@param string $sChild Child reference.
	 *	@param string $sMessage Quit message.
	 */
	public function resetChild($sChild, $sMessage = "Rehash!")
	{
		if(($oChild = $this->getChildObject($sChild)) === null)
		{
			return false;
		}

		$oChild->destructBot($sMessage);
		$oChild->constructBot();
		
		return true;
	}
	
	
	/**
	 *	Returns a value from the current bot's configuration.
	 *
	 *	<code>
	 *	echo $this->getChildConfig('nickname');
	 *	// Returns: OUTRAGEbot</code>
	 *
	 *	@param string $sKey Configuration key to lookup.
	 *	@return mixed Value that is returned.
	 */
	public function getChildConfig($sKey)
	{
		if(isset($this->oCurrentBot->aConfig[$sKey]))
		{
			return $this->oCurrentBot->aConfig[$sKey];
		}
		
		return null;
	}
	
	
	/**
	 *	Returns a value from the network configuration. This
	 *	is anything that is within [~Network].
	 *
	 *	<code>
	 *	echo $this->getNetworkConfig('name');
	 *	// Returns: FFSNetwork
	 *	</code>
	 *
	 *	@param string $sKey Configuration key to lookup.
	 *	@return mixed Value that is returned.
	 */
	public function getNetworkConfig($sKey)
	{
		if(isset($this->oConfig->Network[$sKey]))
		{
			return $this->oConfig->Network[$sKey];
		}
		
		return null;
	}
	
	
	/**
	 *	Sends RAW IRC Messages to the server.
	 *
	 *	There are many different ways of sending a message with this
	 *	function - this covers all outbound functions. There are three
	 *	different ways, using the definitions, a string of a child name,
	 *	or an array of children's names.
	 *
	 *	<b>as Definitions:</b>
	 *	 - SEND_MAST: Sends a message from the master child.
	 *	 - SEND_CURR: Sends a message from the current child.
	 *	 - SEND_DIST: Sends a message from each child in succession.
	 *	 - SEND_ALL: Send a message from all children at the same time.
	 *
	 *	<b>as a String:</b>
	 *	You can send a message from a child's name. For children that
	 *	are defined in the configuration, it will be their original
	 *	nickname, whilst for bots created later, it will be name you
	 *	give them.
	 *
	 *	<b>as an Array:</b>
	 *	You can send messages only from selected children. The same note
	 *	above applies.
	 *
	 *	<code>
	 *	$this->sendRaw('PRIVMSG #Westie :hai there');		                   // Use default settings.
	 *	$this->sendRaw('PRIVMSG #Westie :Everyone says hai!', SEND_ALL);           // All children.
	 *	$this->sendRaw('PRIVMSG #Westie :OUTRAGEbot says hai!', 'OUTRAGEbot');     // From the OUTRAGEbot child.
	 *	</code>
	 *
	 *	@param string $sMessage Raw IRC message you want to send.
	 *	@param mixed $mSend How to send the message (Look above).
	 */
	public function sendRaw($sMessage, $mSend = SEND_CURR)
	{
		if(is_int($mSend))
		{
			if($mSend == SEND_DEF)
			{
				$mSend = $this->oConfig->Network['rotation'];
			}

			switch($mSend)
			{
				case SEND_MAST:
				{
					$this->aBotObjects[0]->Output($sMessage);
					break;
				}
				case SEND_CURR:
				{
					$this->oCurrentBot->Output($sMessage);
					break;
				}
				case SEND_ALL:
				{
					foreach($this->aBotObjects as $oBot)
					{
						$oBot->Output($sMessage);
					}
					break;
				}
				case SEND_DIST:
				default:
				{
					$this->getNextChild()->Output($sMessage);
					break;
				}
			}
			
			return true;
		}
		elseif(is_string($mSend))
		{
			$oChild = $this->getChildObject($mSend);
			
			if($oChild != null)
			{
				$oChild->Output($sMessage);
				return true;
			}
			
			return false;
		}
		elseif(is_array($mSend))
		{
			foreach($mSend as $sSend)
			{
				$oChild = $this->getChildObject($mSend);
			
				if($oChild != null)
				{
					$oChild->Output($sMessage);
				}
			}
			
			return true;
		}
	}
	
		
	/**
	 *	Request the modes in a channel - used for user modes.
	 *	Do we need to call this any more?
	 *
	 *	@ignore
	 *	@param string $sChannel Channel name.
	 */
	function getNames($sChannel)
	{
		if(!$sChannel) return false;
		if($this->oCurrentBot->isClone()) return false;
		
		$this->sendRaw("NAMES {$sChannel}");
		return true;
	}
	
	
	/**
	 *	Get the users nickname from a hostname string.
	 *
	 *	<code>$this->getNickname("Westie!westie@typefish.co.uk");</code>
	 *
	 *	@param string $sHost The hostname string
	 *	@return string Nickname
	 */
	public function getNickname($sHost)
	{
		$aDetails = explode('!', $sHost);
		return str_replace(':', '', $aDetails[0]);
	}
	
	
	/**
	 *	Get the users hostname from a hostname string.
	 *
	 *	<code>$this->getHostname("Westie!westie@typefish.co.uk");</code>
	 *
	 *	@param string $sHost The hostname string
	 *	@return string Hostmask
	 */
	public function getHostname($sHost)
	{
		$aDetails = explode('@', $sHost);
		return (isset($aDetails[1]) ? $aDetails[1] : "");
	}

	
	/**
	 *	This function gets the next child along in the queue.
	 *
	 *	<code>
	 *	$child = $this->getNextChild();
	 *	$child->Output('PRIVMSG #Westie :hai!');</code> 
	 *
	 *	@return Socket Child object.
	 */
	public function getNextChild()
	{
		return next($this->aBotObjects) == false ? reset($this->aBotObjects) : current($this->aBotObjects);
	}
	
	
	/**
	 *	Recieve input from the children.
	 *
	 *	@ignore
	 */
	public function getSend(Socket $oBot, $sMessage)
	{
		if(strlen($sMessage) < 3) return;
		
		/* Deal with the useless crap. */
		$this->oCurrentBot = &$oBot;
		$this->sCurrentChunk = $sMessage;
		$aRaw = explode(' ', $sMessage, 4);
		
		/* Deal with realtime scans */
		if($oBot->iUseQueue == true)
		{
			if(array_search($aRaw[1], $oBot->aSearch) === false)
			{
				$oBot->aMsgQueue[] = $sMessage;
			}
			else
			{
				$oBot->aMatchQueue[] = $sMessage;
			}
			return;
		}
		
		/* Let's compare the market, by adding three useless arrays */
		$aChunks = $this->sortChunks($aRaw);
		
		if($this->getChildConfig('reactevent') != 'false')
		{
		}

		/* Deal with pings */
		if($aChunks[0] == 'PING')
		{
			$oBot->Output('PONG '.$aChunks[1]);
			return;
		}
		elseif($aChunks[1] == 'PONG')
		{
			$oBot->iNoReply = 0;
			$oBot->iHasBeenReply = true;
			return;
		}
		
		if($this->getChildConfig('reactevent') == 'false')
		{
			$this->_onRaw($aChunks);
			return;
		}
		
		/* The infamous switchboard, removed! */
		$this->scanHandlers($aChunks, $aRaw);
		$sCallback = '_on'.$aChunks[1];
		
		if(method_exists($this, $sCallback))
		{
			$this->$sCallback($aChunks);
		}
		else
		{
			$this->_onRaw($aChunks);
		}
	}
	
	
	/**
	 *	Parsing and sorting the chunks.
	 *	@ignore
	 */
	public function sortChunks($aChunks)
	{
		$aChunks[0] = isset($aChunks[0]) ? ($aChunks[0][0] == ":" ? substr($aChunks[0], 1) : $aChunks[0]) : "";
		$aChunks[1] = isset($aChunks[1]) ? $aChunks[1] : "";
		$aChunks[2] = isset($aChunks[2]) ? ($aChunks[2][0] == ":" ? substr($aChunks[2], 1) : $aChunks[2]) : "";
		$aChunks[3] = isset($aChunks[3]) ? ($aChunks[3][0] == ":" ? substr($aChunks[3], 1) : $aChunks[3]) : "";
		
		return $aChunks;
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onConnect()
	{
		$this->invokeEvent("onConnect");
		
		if(isset($this->oConfig->Perform))
		{
			foreach((array) $this->oConfig->Perform as $sRaw)
			{
				$this->sendRaw($sRaw);
			}
		}
		
		foreach((array) explode(',', $this->oConfig->Network['channels']) as $sChannel)
		{
			$this->sendRaw("JOIN {$sChannel}");
		}
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onJoin($aChunks)
	{
		$sNickname = $this->getNickname($aChunks[0]);
		
		$this->oModes->aUserInfo[strtolower($sNickname)]['Hostname'] = $this->getHostname($aChunks[0]);
		$this->invokeEvent("onJoin", $sNickname, $aChunks[2]);
		$this->addUserToChannel($aChunks[2], $sNickname);
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onKick($aChunks)
	{
		$aChunks[3] = explode(' ', $aChunks[3], 2);
		$aChunks[3][1] = trim(isset($aChunks[3][1]) ? substr($aChunks[3][1], 1) : "");
		$this->invokeEvent("onKick", $this->getNickname($aChunks[0]), $aChunks[3][0], $aChunks[2], $aChunks[3][1]);
		$this->removeUserFromChannel($aChunks[2], $aChunks[3][0]);
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onPart($aChunks)
	{
		$this->invokeEvent("onPart", $this->getNickname($aChunks[0]), $aChunks[2], $aChunks[3]);
		$this->removeUserFromChannel($aChunks[2], $this->getNickname($aChunks[0]));
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onQuit($aChunks)
	{
		$this->invokeEvent("onQuit", $this->getNickname($aChunks[0]), $aChunks[3]);
		$this->removeUserFromChannel('*', $this->getNickname($aChunks[0]));
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onMode($aChunks)
	{
		$this->invokeEvent("onMode", $aChunks[2], $aChunks[3]);
			
		foreach($this->parseModes($aChunks[3]) as $aMode)
		{
			$iMode = &$this->oModes->aChannels[strtolower($aChunks[2])][$aMode['PARAM']]['iMode'];
			
			switch($aMode['MODE'])
			{
				case 'v':
				{
					$iMode ^= 1;
					break;
				}
				case 'h':
				{
					$iMode ^= 3;
					break;
				}
				case 'o':
				{
					$iMode ^= 7;
					break;
				}
				case 'a':
				{
					$iMode ^= 15;
					break;
				}
				case 'q':
				{
					$iMode ^= 31;
					break;
				}
			}
		}
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onNick($aChunks)
	{
		$sNickname = $this->getNickname($aChunks[0]);
		
		if(!strcmp($sNickname, $this->oCurrentBot->aConfig['nickname']))
		{
			/* God, this IRCnet sucks. :( */
			$this->oCurrentBot->aConfig['nickname'] = $sNickname;
		}
		
		$this->invokeEvent("onNick", $sNickname, $aChunks[2]);
		$this->renameUserFromChannel($sNickname, $aChunks[2]);
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onNotice($aChunks)
	{
		$this->invokeEvent("onNotice", $this->getNickname($aChunks[0]), $aChunks[2], $aChunks[3]);
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onCTCP($aChunks)
	{
		$aChunks[3] = explode(' ', str_replace("\001", "", $aChunks[3]), 2);
		$this->invokeEvent("onCTCP", $this->getNickname($aChunks[0]), $aChunks[2], $aChunks[3][0], (isset($aChunks[3][1]) ? $aChunks[3][1] : ""));
		
		switch(strtoupper($aChunks[3][0]))
		{
			case "VERSION":
			{
				$this->ctcpReply($this->getNickname($aChunks[0]), "VERSION {$this->oConfig->Network['version']}");
				break;
			}
			case "TIME":
			{
				$this->ctcpReply($this->getNickname($aChunks[0]), "TIME ".date("d/m/Y H:i:s", time()));
				break;
			}
			case "PING":
			{
				$this->ctcpReply($this->getNickname($aChunks[0]), "PING {$aChunks[3][1]}");
				break;
			}
			case 'UPTIME':
			{
				$aSince = $this->dateSince($this->oCurrentBot->aStatistics['StartTime']);
				
				$sString = "{$aSince['WEEKS']} weeks, {$aSince['DAYS']} days, {$aSince['HOURS']} hours, ".
				"{$aSince['MINUTES']} minutes, {$aSince['SECONDS']} seconds.";
				
				$this->ctcpReply($this->getNickname($aChunks[0]), "UPTIME ".$sString);
				break;
			}
			case 'START':
			{
				$this->ctcpReply($this->getNickname($aChunks[0]), "START ".date("d/m/Y H:i:s", $this->oCurrentBot->aStatistics['StartTime']));
				break;
			}
		}
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onPrivmsg($aChunks)
	{
		if($aChunks[3][0] == Format::CTCP)
		{
			$this->_onCTCP($aChunks);
			return;
		}
		
		switch($aChunks[2][0])
		{
			case '&':
			case '#':
			{
				if($aChunks[3][0] == $this->oConfig->Network['delimiter'])
				{
					$aCommand = explode(' ', trim($aChunks[3]), 2);
					$this->invokeEvent("onCommand", $this->getNickname($aChunks[0]), $aChunks[2],
						substr($aCommand[0], 1), (isset($aCommand[1]) ? $aCommand[1] : ""));
						
					return;
				}
				
				$this->invokeEvent("onMessage", $this->getNickname($aChunks[0]), $aChunks[2], $aChunks[3]);
				return;
			}
			default:
			{
				$this->invokeEvent("onPrivMessage", $this->getNickname($aChunks[0]), $aChunks[3]);
				return;
			}
		}
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onTopic($aChunks)
	{
		$sNickname = $this->getNickname($aChunks[0]);
		
		$this->oModes->aChannelInfo[strtolower($aChunks[2])]['TopicString'] = $aChunks[3];
		$this->oModes->aChannelInfo[strtolower($aChunks[2])]['TopicSetTime'] = time();
		$this->oModes->aChannelInfo[strtolower($aChunks[2])]['TopicSetBy'] = $sNickname;
	
		$this->invokeEvent("onTopic", $sNickname, $aChunks[2], $aChunks[3]);
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onError($aChunks)
	{
	}
	
	
	/**
	 *	@ignore
	 */
	private function _on353($aChunks)
	{
		$aData = explode(" ", $aChunks[3], 3);
		$aData[2] = substr($aData[2], 1);
		$aUsers = explode(" ", $aData[2]);
		$sChan = strtolower($aData[1]);
				
		/* Great, we now parse the users... */
		foreach($aUsers as $sUser)
		{
			$iTemp = 0;
			$sUser = trim($sUser);
				
			if(!isset($sUser[0]))
			{
				continue;
			}
				
			switch($sUser[0])
			{
				case '+': $iTemp = 1; break;
				case '%': $iTemp = 3; break;
				case '@': $iTemp = 7; break;
				case '&': $iTemp = 15; break;
				case '~': $iTemp = 31; break;
				default: break;
			}
					
			$sUser = preg_replace("/[+%@&~]/", "", $sUser);
			$this->oModes->aChannels[$sChan][$sUser]['iMode'] = $iTemp;
			$this->oModes->aUsers[$sUser][$sChan] = true;
		}
	}
	
	
	/**
	 *	@ignore
	 */
	private function _onRaw($aChunks)
	{
		switch($aChunks[1])
		{
			/* When the bot connects */
			case 001:
			{
				$this->_onConnect();
				return;
			}
			
			/* Nick already in use. */
			case 433:
			{
				if($this->getChildConfig('altnick') != null)
				{
					$sNewNick = $this->getChildConfig('altnick');
				}
				else
				{
					$sNewNick = $this->getChildConfig('nickname').rand(10, 99);
				}
				
				$this->oCurrentBot->setNickname($sNewNick);
				return;
			}
			
			/* Topic information */
			case 332:
			{
				$aData = explode(' :', $aChunks[3], 2);
				$this->oModes->aChannelInfo[strtolower($aData[0])]['TopicString'] = $aData[1];
				$this->getWhoList($aData[0]);
				return;
			}
			
			case 333:
			{
				$aData = explode(' ', $aChunks[3], 3);				
				$this->oModes->aChannelInfo[strtolower($aData[0])]['TopicSetTime'] = $aData[2];
				$this->oModes->aChannelInfo[strtolower($aData[0])]['TopicSetBy'] = $aData[1];
				return;
			}
		}
		
		/* Other stuff */
		if($aChunks[3][0] == Format::CTCP)
		{
			$this->_onCTCP($aChunks);
			return;
		}
	}
	
	
	/**
	 *	Adds a user to the channel's database. Used internally.
	 *
	 *	@ignore
	 *	@param string $sChan Channel where user is.
	 *	@param string $sUser Nickname to remove from list.
	 */
	public function addUserToChannel($sChan, $sUser)
	{
		$this->oModes->aUsers[$sUser][strtolower($sChan)] = true;
	}
	
	
	/**
	 *	Removes a user from the channel's database. Used internally. '*' signifies all.
	 *
	 *	@ignore
	 *	@param string $sChan Channel where user is.
	 *	@param string $sUser Nickname to remove from list.
	 */
	public function removeUserFromChannel($sChan, $sUser)
	{
		if($sChan != '*')
		{
			unset($this->oModes->aChannels[strtolower($sChan)][$sUser]);
			return;
		}
		
		foreach($this->oModes->aUsers[$sUser] as $sChannel => $mUnused)
		{
			unset($this->oModes->aChannels[$sChannel][$sUser]);
			unset($this->oModes->aUsers[$sUser][$sChannel]);
		}
		return;
	}
	
	
	/**
	 *	Renames a user from the channel's database. Used internally.
	 *
	 *	@ignore
	 *	@param string $sOldNick The old nickname.
	 *	@param string $sNewNick The new nickname.
	 */
	public function renameUserFromChannel($sOldNick, $sNewNick)
	{
		foreach($this->oModes->aUsers[$sOldNick] as $sChannel => $mUnused)
		{
			$this->oModes->aChannels[$sChannel][$sNewNick] = $this->oModes->aChannels[$sChannel][$sOldNick];
			unset($this->oModes->aChannels[$sChannel][$sOldNick]);
		}
		
		$this->oModes->aUsers[$sNewNick] = $this->oModes->aUsers[$sOldNick];	
		unset($this->oModes->aUsers[$sOldNick]);
		
		return;
	}
	
	
	/**
	 *	Returns user information from a channel.
	 *
	 *	<code>$aInfo = $this->getUserInfoFromChannel("#ffs", "Westie");</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return array Returns an array on success, FALSE on failure.
	 */
	public function getUserInfoFromChannel($sChan, $sUser)
	{
		$sChan = strtolower($sChan);
		
		if(!isset($this->oModes->aChannels[$sChan][$sUser]))
		{
			return false;
		}
		
		return $this->oModes->aChannels[$sChan][$sUser];
	}
	
	
	/**
	 *	Returns information about the user in an OOP format. This only
	 *	currently retrieves channel information.
	 *
	 *	<code>$oUser = $this->getUser('Westie');</code>
	 *
	 *	<code>
	 *	stdClass Object
	 *	(
	 *		[Channels] => Array
	 *			(
	 *				[#westie] => Array
	 *				(
	 *					[CHANNEL] => #westie
	 *					[USERMODE] => ~
	 *				)
	 *				...
	 *			)
	 *	)</code>
	 *
	 *	@param string $sNickname Nickname you want to get data for.
	 *	@return stdClass Class with information.
	 */
	public function getUser($sNickname)
	{
		$pUser = new stdClass();
		$pUser->Channels = array();
		
		foreach($this->oModes->aUsers[$sNickname] as $sChannel => $uVoid)
		{
			$iUserMode = $this->oModes->aChannels[$sChannel][$sNickname]['iMode'];
			$sUserMode = "";
			
			switch($iUserMode)
			{
				case 1:
				{
					$sUserMode = "+";
					break;
				}
				case 3:
				{
					$sUserMode = "%";
					break;
				}
				case 7:
				{
					$sUserMode = "@";
					break;
				}
				case 15:
				{
					$sUserMode = "&";
					break;
				}
				case 31:
				{
					$sUserMode = "~";
					break;
				}
				default:
				{
					$sUserMode = "-";
				}
			}
			
			$pUser->Channels[$sChannel] = array
			(
				"CHANNEL" => $sChannel,
				"USERMODE" => $sUserMode,
			);
		}
		
		$aWhois = $this->getWhois($sNickname);
		
		$pUser->Connection = array
		(
			'Server' => $aWhois['SERVER']['SERVER'],
			'Information' => $aWhois['SERVER']['INFO'],
		);
		
		$pUser->Information = array
		(
			'Nickname' => $sNickname,
			'Username' => $aWhois['INFO']['USERNAME'],
			'Realname' => $aWhois['INFO']['REALNAME'],
			'Hostname' => $aWhois['INFO']['HOSTNAME'],
		);
		
		return $pUser;
	}
	
	
	/**
	 *	Returns a stdClass instance of the information about a channel.
	 *
	 *	@param string $sChannel Channel name
	 *	@return stdClass Channel information
	 */
	public function getChannel($sChannel)
	{
		$pChannel = new stdClass();
		$sChannel = strtolower($sChannel);
		
		$pChannel->Users = array();
		
		foreach($this->oModes->aChannels[$sChannel] as $sKey => $aUser)
		{
			$iUserMode = $aUser['iMode'];
			$sUserMode = "";
			
			switch($iUserMode)
			{
				case 1:
				{
					$sUserMode = "+";
					break;
				}
				case 3:
				{
					$sUserMode = "%";
					break;
				}
				case 7:
				{
					$sUserMode = "@";
					break;
				}
				case 15:
				{
					$sUserMode = "&";
					break;
				}
				case 31:
				{
					$sUserMode = "~";
					break;
				}
				default:
				{
					$sUserMode = "-";
				}
			}
			
			$pChannel->Users[] = array
			(
				"NICKNAME" => $sKey,
				"USERMODE" => $sUserMode,
			);
		}
		
		return $pChannel;
	}
	
	
	/**
	 *	Checks if that user is actually in that channel.
	 *
	 *	<code>$this->isUserInChannel('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserInChannel($sChan, $sUser)
	{
		return isset($this->oModes->aUsers[$sUser][strtolower($sChan)]) != false;
	}
	
	
	/**
	 *	Checks if the current child is in the channel.
	 *
	 *	<code>$this->isBotInChannel('#ffs');
	 *
	 *	@param string $sChan Channel to check.
	 *	@return bool 'true' on success.
	 */
	public function isBotInChannel($sChan)
	{
		return isset($this->oModes->aUsers[$this->getChildConfig('nickname')][strtolower($sChan)]) != false;
	}
	
	
	/**
	 *	Checks if that user has voice in that channel. Voicers have the
	 *	mode ' + '.
	 *
	 *	<code>$this->isUserVoice('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserVoice($sChan, $sUser)
	{
		$aUser = $this->getUserInfoFromChannel($sChan, $sUser);
		
		if($aUser === false)
		{
			return false;
		}
		
		return ($aUser['iMode'] & MODE_USER_VOICE) != 0; 
	}
	
	
	/**
	 *	Checks if that user has half-op in that channel. Half operators
	 *	have the mode ' % ', and may not be available on all networks.
	 *
	 *	<code>$this->isUserHalfOp('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserHalfOp($sChan, $sUser)
	{
		$aUser = $this->getUserInfoFromChannel($sChan, $sUser);
		
		if($aUser === false)
		{
			return false;
		}
		
		return ($aUser['iMode'] & MODE_USER_HOPER) != 0; 
	}
	
	
	/**
	 *	Checks if that user has operator in that channel. Operators have
	 *	the mode ' @ '.
	 *
	 *	<code>$this->isUserOper('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserOper($sChan, $sUser)
	{
		$aUser = $this->getUserInfoFromChannel($sChan, $sUser);
		
		if($aUser === false)
		{
			return false;
		}
		
		return ($aUser['iMode'] & MODE_USER_OPER) != 0; 
	}
	
	
	/**
	 *	Checks if that user has admin in that channel. Admins have the
	 *	mode ' & ', and may not be available on all networks.
	 *
	 *	<code>$this->isUserAdmin('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserAdmin($sChan, $sUser)
	{
		$aUser = $this->getUserInfoFromChannel($sChan, $sUser);
		
		if($aUser === false)
		{
			return false;
		}
		
		return ($aUser['iMode'] & MODE_USER_ADMIN) != 0; 
	}
	
	
	/**
	 *	Checks if that user has owner in that channel. Owners have the
	 *	mode ' ~ ', and may not be available on all networks.
	 *
	 *	<code>$this->isUserOwner('#ffs', 'Westie');</code>
	 *
	 *	@param string $sChan Channel where user is
	 *	@param string $sUser Nickname to check
	 *	@return bool 'true' on success.
	 */
	public function isUserOwner($sChan, $sUser)
	{	
		$aUser = $this->getUserInfoFromChannel($sChan, $sUser);
		
		if($aUser === false)
		{
			return false;
		}
		
		return ($aUser['iMode'] & MODE_USER_OWNER) != 0; 
	}
		
	
	/**
	 *	Check if the current, active IRC user is a bot admin.
	 *
	 *	<code>$this->isAdmin();</code>
	 *
	 *	@return bool 'true' on success.
	 */
	public function isAdmin()
	{
		$aChunks = explode(' ', $this->sCurrentChunk, 4);
		$sHostname = $this->getHostname($aChunks[0]);
		
		return (in_array($sHostname, $this->oConfig->Network['_owners']) !== false);
	}
	
	
	/**
	 *	Checks if either the current instance, or a specific instance is
	 *	actually a child. Children differ from master bots in one variable.
	 *
	 *	<code>
	 *	if($this->isChild() == false)
	 *	{
	 *		... // This will only be executed by non-children bots.
	 *	}</code>
	 *
	 *	@param string $sChild Child reference.
	 *	@return bool true on success, null if child is non-existant.
	 */
	public function isChild($sChild = "")
	{
		$oChild = ($sChild == "" ? $this->oCurrentBot : $this->getChildObject($sChild));
		
		if($oChild == null)
		{
			return null;
		}
		
		return $oChild->isClone();
	}


	/**
	 *	Invokes an event/callback from plugins.
	 *
	 *	<code>$this->invokeEvent("onTick");
	 *	$this->invokeEvent("onCommand", $sNickname, $sChannel, $sCommand, $sArguments);</code>
	 *
	 *	@param string $sEvent Event to invoke
	 *	@param mixed $... Arguments to pass to event.
	 *	@return void
	 */
	public function invokeEvent($sEvent)
	{
		if(func_num_args() == 0)
		{
			return false;
		}
		
		$aArguments = func_get_args();
		array_shift($aArguments);
		
		foreach($this->oPlugins as &$oPlugin)
		{
			$rResult = call_user_func_array(array($oPlugin, $sEvent), $aArguments);
			
			if($rResult == null || $rResult == true)
			{
				continue;
			}
			
			break;
		}
	}
	
	
	/**
	 *	Strips the text of formatting.
	 *
	 *	@param string $sText Text to strip
	 *	@return string Stripped text
	 */
	public function stripFormat($sText) 
	{
		return preg_replace("/[\002\017\001\026\001\037]/", "", $sText);
	}
	
	
	/**
	 *	Strips the text of colours.
	 *
	 *	@param string $sText Text to strip
	 *	@return string Stripped text
	 */
	public function stripColour($sText)
	{
		return preg_replace("/\003[0-9]{1,2}(,[0-9]{1,2})?/", "", $sText);
	}
	
	
	/**
	 *	Strips the text of formatting and colours.
	 *
	 *	@param string $sText Text to strip
	 *	@return string Stripped text
	 */
	public function stripAll($sText)
	{
		return preg_replace("/[\002\017\001\026\001\037]/", "", 
		preg_replace("/\003[0-9]{1,2}(,[0-9]{1,2})?/", "", $sText));
	}
	
	
	/**
	 *	Sends a message to the specified channel.
	 *
	 *	<code>$this->Message('#ffs', 'some message here');</code>
	 *
	 *	@param string $sChannel Channel name or nickname
	 *	@param string $sMessage Message to send
	 *	@param integer $mSend Method to send messages (see sendRaw() for details)
	 *	@see Master::sendRaw()
	 */
	public function Message($sChannel, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("PRIVMSG {$sChannel} :{$sMessage}", $mSend);
	}
	
	
	/**
	 *	Sends an action to the specified channel.
	 *
	 *	<code>$this->Action('#ffs', 'likes Westie');</code>
	 *
	 *	@param string $sChannel Channel name
	 *	@param string $sMessage Message to send
	 *	@param integer $mSend Method to send messages (see sendRaw() for details)
	 *	@see Master::sendRaw()
	 */
	public function Action($sChannel, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("PRIVMSG {$sChannel} :".chr(1)."ACTION {$sMessage}".chr(1), $mSend);
	}
	
	
	/**
	 *	Sends a raw IRC message.
	 *
	 *	@ignore
	 */
	public function Raw($sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw($sMessage, $mSend);
	}
	
	
	/**
	 *	Sends a notice to the specified channel.
	 *
	 *	<code>$this->Notice('Westie', 'Here is your password!');</code>
	 *
	 *	@param string $sNickname Nickname
	 *	@param string $sMessage Message to send
	 *	@param integer $mSend Method to send messages (see sendRaw() for details)
	 *	@see Master::sendRaw()
	 */
	public function Notice($sNickname, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("NOTICE {$sNickname} :{$sMessage}", $mSend);
	}
	
	
	/**
	 *	Sends a message to the specified channel.
	 *
	 *	@ignore
	 */
	public function sendMessage($sChannel, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("PRIVMSG {$sChannel} :{$sMessage}", $mSend);
	}
	
	
	/**
	 *	Sends an action to the specified channel.
	 *
	 *	@ignore
	 */
	public function sendAction($sChannel, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("PRIVMSG {$sChannel} :".chr(1)."ACTION {$sMessage}".chr(1), $mSend);
	}
	
	
	/**
	 *	Sends a notice to the specified channel.
	 *
	 *	@ignore
	 */
	public function sendNotice($sNickname, $sMessage, $mSend = SEND_DEF)
	{
		return $this->sendRaw("NOTICE {$sNickname} :{$sMessage}", $mSend);
	}
	
	
	/**
	 *	Sends a CTCP reply.
	 *
	 *	<code>$this->ctcpReply('Westie', 'COMMAND something here');</code>
	 *
	 *	@param string $sNickname Nickname
	 *	@param string $sMessage CTCP reply
	 *	@see Master::sendRaw()
	 */
	public function ctcpReply($sNickname, $sMessage)
	{
		return $this->sendRaw("NOTICE {$sNickname} :".chr(1).trim($sMessage).chr(1), SEND_CURR);
	}
	
	
	/**
	 *	Sends a CTCP request.
	 *
	 *	<code>$this->ctcpRequest('deLUX', 'VERSION');</code>
	 *
	 *	@param string $sNickname Nickname
	 *	@param string $sRequest CTCP request
	 *	@param integer $mSend Method to send messages (see sendRaw() for details)
	 *	@see Master::sendRaw()
	 */
	public function ctcpRequest($sNickname, $sRequest, $mSend = SEND_DEF)
	{
		return $this->sendRaw("PRIVMSG {$sNickname} :".chr(1).trim($sRequest).chr(1), $mSend);
	}


	/**
	 *	Creates a timer, note that arguments to be passed to $cCallback to after $iRepeat.
	 *
	 *	<code>
	 *	$sKey = $this->addTimer(array($this, 'Message'), '0.5000', '10', '#OUTRAGEbot', 'Test Message');
	 *	$sKey = $this->addTimer('sampleTimer', '10', '-1');
	 *	</code>
	 *
	 *	@param callback $cCallback Timer callback 
	 *	@param float $fInterval <b>Seconds</b> (decimals can be used) between timer calls.
	 *	@param integer $iRepeat How many times the timer should call before it is destroyed. -1 implies infinite.
	 *	@param mixed $... Arguments to pass to timer.
	 *	@return string Timer reference ID.
	 */
	public function addTimer($cCallback, $fInterval, $iRepeat)
	{
		$aArguments = func_get_args();
		array_shift($aArguments);
		array_shift($aArguments);
		array_shift($aArguments);
		
		return Timers::Create($cCallback, $fInterval, $iRepeat, (array) $aArguments); 
	}

		
	/**
	 *	Gets the information of a timer from its reference ID.
	 *
	 *	These are the contents of the array that is returned when this function is invoked.
	 *	
	 *	<pre>
	 *	<b>CALLBACK</b>  -> Callback which the timer calls when invoked.
	 *	<b>INTERVAL</b>  -> How many seconds pass between each call.
	 *	<b>REPEAT</b>    -> How many times left the plugin is called before it is unlinked.
	 *	<b>CALLTIME</b>  -> When the plugin will next call itself (Unix time).
	 *	<b>ARGUMENTS</b> -> Array of arguments that will be passed to the timer.
	 *	</pre>
	 *
	 *	<code>$aTimer = $this->getTimer($sKey);</code>
	 *
	 *	@param string $sKey Timer reference ID.
	 *	@return array Array of timer information.
	 */
	public function getTimer($sKey)
	{
		return Timers::Get($sKey);
	}
	
	
	/**
	 *	Removes a timer from memory.
	 *
	 *	<code>$this->removeTimer($sKey);</code>
	 *
	 *	@param string $sKey Timer reference ID.
	 *	@return bool 'true' on success.
	 */
	public function removeTimer($sKey)
	{
		return Timers::Delete($sKey);
	}
	
	
	/**
	 *	Loads a plugin from the plugin directory.
	 *
	 *	<code>$this->addPlugin('AutoInvite');</code>
	 *
	 *	@param string $sPlugin
	 *	@return bool 'true' on success.
	 */
	public function addPlugin($sPlugin)
	{
		if(array_key_exists($sPlugin, $this->oPlugins))
		{
			return false;
		}

		$sDirname = BASE_DIRECTORY."/Plugins/{$sPlugin}/Default.php";

		if(!file_exists($sDirname))
		{
			return false;
		}

		$sIdentifier = substr($sPlugin, 0, 8).'_'.substr(sha1(time()."-".uniqid()), 2, 10);
		$sClass = file_get_contents($sDirname); // Ouch, this has gotta hurt.

		if(!preg_match("/class[\s]+?".$sPlugin."[\s]+?extends[\s]+?Plugins[\s]+?{/", $sClass))
		{
			return false;
		}
			
		$sClass = preg_replace("/(class[\s]+?)".$sPlugin."([\s]+?extends[\s]+?Plugins[\s]+?{)/", "\\1".$sIdentifier."\\2", $sClass);
		$sFile = tempnam(dirname($sDirname), "mod"); // Stops the __FILE__ bugs.
		file_put_contents($sFile, $sClass);				
		unset($sClass); // Weight off the shoulders anyone?
			
		include($sFile);
		unlink($sFile);
				
		$this->oPlugins->$sPlugin = new $sIdentifier($this, array($sPlugin, $sIdentifier));
		echo "* Plugin ".$sPlugin." has been loaded.".PHP_EOL;
		
		return true;
	}
	

	/**
	 *	Gets the instance of the plugin if it exists.
	 *
	 *	<code>$this->getPlugin('Callbacks')->getCode();</code>
	 *
	 *	@param string $sPlugin Plugin name
	 *	@return Plugin Object of the plugin.
	 */
	public function getPlugin($sPlugin)
	{
		if(isset($this->oPlugins->$sPlugin))
		{
			return $this->oPlugins->$sPlugin;
		}

		return null;
	}


	/**
	 *	Unloads an active plugin from memory.
	 *
	 *	<code>$this->removePlugin('AutoInvite');</code>
	 *
	 *	@param string $sPlugin
	 *	@return bool 'true' on success.
	 */
	public function removePlugin($sPlugin)
	{
		if(isset($this->oPlugins->$sPlugin))
		{
			$this->oPlugins->$sPlugin->__destruct();
			unset($this->oPlugins->$sPlugin);
			Timers::CheckCall();
			$this->checkHandlers();
			echo "* Plugin ".$sPlugin." has been unloaded.".PHP_EOL;
			return true;
		}
		
		return false;
	}
	
	
	/**
	 *	Unloads and reloads a plugin.
	 *
	 *	<code>$this->reloadPlugin('AutoInvite');</code>
	 *
	 *	@param string $sPlugin
	 *	@return bool 'true' on success.
	 */
	public function reloadPlugin($sPlugin)
	{
		$this->removePlugin($sPlugin);
		return $this->addPlugin($sPlugin);
	}
	
	
	/**
	 *	Check if a plugin is loaded into memory.
	 *
	 *	<code>
	 *	if($this->isPluginLoaded('AutoInvite'))
	 *	{
	 *		...
	 *	}</code>
	 *
	 *	@param string $sPlugin
	 *	@return bool 'true' on success.
	 */
	public function isPluginLoaded($sPlugin)
	{
		return isset($this->oPlugins->$sPlugin);
	}
		
	
	/**
	 *	Create a command or event handler for IRC numerics/commands.
	 *
	 *	If you are passing arguments to the bind handler, then <b>must</b> $aFormat must be populated.
	 *	If you do not want to pass arguments, you can either assign $aFormat to false or a blank array.
	 *	If you want the full string, assign $aFormat to be true.
	 *	Otherwise, when using $aFormat, numeric characters are replaced with their corresponding chunk when called.
	 *	For a detailed explanation, please check the example _link_.
	 *
	 *	<code>$this->iBindID = $this->addHandler("INVITE", "onInvite", array(2, 3));</code>
	 *
	 *	You can use this function to add a handler for commands. In this case, the $sCommand argument becomes
	 *	'COMMAND', the $cCallback argument remains the same, and $aFormat has a different meaning in this context,
	 *	it is the name of the function (as a string) that is associated with the handler. For a detailed example,
	 *	check the bottom link.
	 *
	 *	<code>$this->aCommand['info'] = $this->addHandler('COMMAND', 'test_func', 'test-func');
	 *
	 *	@example OUTRAGEbot/~Examples/addHandler.php A demo plugin that demonstrates how to use it.
	 *	@param string $sInput either: IRC command/numeric name, or: 'COMMAND' for a text-based channel command.
	 *	@param callback $cCallback Callback to bind handler.
	 *	@param array $aFormat Array of arguments to pass to the bind handler.
	 *	@return string Bind resource ID.
	 */
	public function addHandler($sInput, $cCallback, $aFormat = array())
	{
		$sHandle = substr(sha1(time()."-".uniqid()), 2, 10);
		
		$this->aHandlers[$sHandle] = array
		(
			"INPUT" => strtoupper($sInput),
			"CALLBACK" => $cCallback,
			"FORMAT" => $aFormat,
			"MATCHES" => false,
		);
		
		return $sHandle;
	}
	
	
	/**
	 *	Create a regex handler.
	 */
	public function addComplexHandler($sInput, $aMatches, $cCallback, $aFormat = array())
	{
		$sHandle = substr(sha1(time()."-".uniqid()), 2, 10);
		
		$this->aHandlers[$sHandle] = array
		(
			"INPUT" => strtoupper($sInput),
			"CALLBACK" => $cCallback,
			"FORMAT" => $aFormat,
			"MATCHES" => $aMatches,
		);
		
		return $sHandle;
	}
	
	   
	/**
	 *	Gets the information of a bind from its reference ID.
	 *
	 *	These are the contents of the array that is returned when this function is invoked.
	 *	<pre>
	 *	<b>CALLBACK</b> -> Callback which the bind calls when invoked.
	 *	<b>FORMAT</b>   -> Arguments to pass to the event. See addHandler.
	 *	<b>INPUT</b>    -> The IRC command matches the bind.
	 *	</pre>
	 *
	 *	<code>$this->getHandler($sKey);</code>
	 *
	 *	@param string $sKey Bind reference ID.
	 *	@return array Array of bind information.
	 */
	public function getHandler($sKey)
	{
		if(isset($this->aHandlers[$sKey]))
        {
			return $this->aHandlers[$sKey];
		}

		return null;
	}

	
	/**
	 *	Delete a reference to a bind handler.
	 *
	 *	<code>$this->removeHandler($sKey);</code>
	 *
	 *	@param string $sKey
	 *	@return bool 'true' on success.
	 */
	public function removeHandler($sKey)
	{
		if(isset($this->aHandlers[$sKey]))
		{
			unset($this->aHandlers[$sKey]);
			return true;
		}
		
		return false;
	}
	
	
	/**
	 *	Scans through the bind handlers.
	 *
	 *	@param string $sKey
	 *	@ignore
	 */
	public function scanHandlers(&$aChunks, &$aRaw)
	{
		$aChunks[1] = strtoupper($aChunks[1]);
	
		foreach($this->aHandlers as $aSection)
		{
			if(!isset($aChunks[1])) return;
			
			if($aChunks[1] == 'PRIVMSG')
			{
				if($aSection['INPUT'] == 'COMMAND')
				{
					$aCommand = explode(' ', $aChunks[3], 2);

					if($aCommand[0] == $this->oConfig->Network['delimiter'].$aSection['FORMAT'])
					{
						call_user_func($aSection['CALLBACK'], $this->getNickname($aChunks[0]),
							$aChunks[2], (isset($aCommand[1]) ? $aCommand[1] : ""));
					}
				}
			}
			
			if($aSection['INPUT'] != $aChunks[1])
			{
				continue;
			}
			
			$aArguments = array();
			
			if($aSection['FORMAT'] === true)
			{
				$aArguments = implode(' ', $aChunks);
			}
			else
			{
				foreach($aSection['FORMAT'] as $mFormat)
				{
					$aArguments[] = (is_integer($mFormat) ? $aChunks[$mFormat] : $mFormat);					
				}
			}
			
			if($aMatches['MATCHES'] !== false)
			{
			
				foreach($aMatches['MATCHES'] as $iValue => $sValue)
				{
					if(preg_match($sValue, $aChunks[$iValue]) == false)
					{
						continue;
					}
				}
			}
			
			call_user_func_array($aSection['CALLBACK'], $aArguments);
		}
	}
	
	
	/**
	 *	This function loops through all current handlers, and if they are not callable (plugin instance
	 *	is removed, eg.), then the handler is removed. 
	 */
	public function checkHandlers()
	{
		foreach($this->aHandlers as $sKey => $aHandle)
		{
			if(!is_callable($aHandle['CALLBACK']))
			{
				unset($this->aHandlers[$sKey]);
			}
		}
	}
		
	
	/**
	 *	Request information realtime.
	 *
	 *	The data that are you requesting (for instance, what is in $mSearch) will not be parsed by the bot.
	 *	This essentially means it is the job of the code using that request to deal with parsing it properly.
	 *
	 *	<code>$aMatches = $this->getRequest("NAMES #westie", array(353, 366), 4);
	 *
	 *	// Array
	 *	// (
	 *	//	 [0] => :ircd 353 OUTRAGEbot = #westie :OUTRAGEbot ~Westie
	 *	//	 [1] => :ircd 366 OUTRAGEbot #westie :End of /NAMES list.
	 *	// )</code>
	 *
	 *	@param string $sRequest Message to send to the server.
	 *	@param mixed $mSearch IRC numerics to cache.
	 *	@param integer $iSleep <i>Microseconds</i> to sleep before getting input.
	 *	@return array The response matched to the data in $aSearch.
	 */
	public function getRequest($sRequest, $mSearch, $iSleep = 10000)
	{
		$this->oCurrentBot->iUseQueue = true;
		$this->oCurrentBot->aSearch = (array) $mSearch;
		$this->oCurrentBot->aMatchQueue = array();
		$this->oCurrentBot->Output($sRequest);
		usleep($iSleep);
		$this->oCurrentBot->Input();
		$this->oCurrentBot->iUseQueue = false;
		
		return $this->oCurrentBot->aMatchQueue;
	}
	
	
	/**
	 *	Invites a user to a channel.
	 *
	 *	<code>$this->Invite("#ffs", "Westie");</code>
	 *
	 *	@param string $sChannel Channel name.
	 *	@param string $sNickname Nickname of the person to kick.
	 *	@param string $sReason Reason of the kick.
	 */
	public function Invite($sChannel, $sNickname)
	{
		return $this->sendRaw("INVITE {$sNickname} {$sChannel}");
	}
	
	
	/**
	 *	Kicks a user from a channel.
	 *
	 *	<code>$this->Kick("#ffs", "Woet", "Dutchy");</code>
	 *
	 *	@param string $sChannel Channel name.
	 *	@param string $sNickname Nickname of the person to kick.
	 *	@param string $sReason Reason of the kick.
	 */
	public function Kick($sChannel, $sNickname, $sReason = "Kick")
	{
		return $this->sendRaw("KICK {$sChannel} :{$sReason}");
	}
	
	
	/**
	 *	Allows the bot to join a channel.
	 *
	 *	<code>$this->Join("#OUTRAGEbot");</code>
	 *
	 *	@param string $sChannel Channel name (IRC format applies!).
	 */
	public function Join($sChannel)
	{
		return $this->sendRaw("JOIN $sChannel");
	}
	
	
	/**
	 *	Allows the bot to part a channel.
	 *
	 *	<code>$this->Part("#ffs");</code>
	 *
	 *	@param string $sChannel Channel name.
	 *	@param string $sReason Reason for leaving
	 */
	public function Part($sChannel, $sReason = false)
	{
		return $this->sendRaw("PART $sChannel".($sReason == false ? "" : " :{$sReason}"));
	}
	
	
	/**
	 *	Set the modes on a channel.
	 *
	 *	<code>$this->Mode('#OUTRAGEbot', '+o Westie');</code>
	 *
	 *	@param string $sChannel Channel name
	 *	@param string $sMode Mode to be set
	 */
	public function Mode($sChannel, $sMode)
	{
		return $this->Raw('MODE '.$sMessage.' '.$sMode);
	}
	
	
	/**
	 *	Parses a mode string into a usable array.
	 *
	 *	<code>$this->parseModes('+o Westie');</code>
	 *
	 *	@param $sModes Mode that has just been set.
	 *	@return array Array of modes.
	 */
	public function parseModes($sMode)
	{
		$iAction = 0;
		$iIndex = 0;
		$aModes = explode(' ', $sMode);
		$iModes = strlen($aModes[0]);
		$aReturn = array();

		for($iCount = 0; $iCount < $iModes; ++$iCount)
		{
			if($aModes[0][$iCount] == '+' || $aModes[0][$iCount] == '-')
			{
				$iAction = $aModes[0][$iCount];
				continue;
			}
			
			if(isset($aModes[++$iIndex]))
			{
				$aReturn[] = array
				(
					"ACTION" => $iAction,
					"MODE" => $aModes[0][$iCount],
					"PARAM" => $aModes[$iIndex],
				);
			}
		}
		
		return $aReturn;
	}
	
	
	/**
	 *	Returns current WHOIS data about a user into an array.
	 -
	 -	Please forgive me, for I have sinned with a lot of HTML.
	 -	You should be able to figure out what this is all about anyway.
	 -
	 *	A list of rows that this function returns when called.
	 *	<pre>
	 *	<b>INFO:</b>
	 *		|- <b>USERNAME</b> -> The username of the user.
	 *		|- <b>HOSTNAME</b> -> The user's hostname.
	 *		'- <b>REALNAME</b> -> The user's realname.
	 *	 
	 *	<b>SERVER:</b>
	 *		|- <b>SERVER</b>   -> The server's address.
	 *		'- <b>REALNAME</b> -> The server/network name.
	 *
	 *	<b>CHANNELS</b> -> An array of all the channels (with modes) that the user is in.
	 *	</pre>
	 *
	 *
	 *
	 *	<code>$this->getWhois('Westie');</code>
	 *
	 *	@param string $sNickname Nickname of the user.
	 *	@param integer $iDelay Microseconds to wait before fetching input.
	 *	@return array Array of modes.
	 */
	public function getWhois($sNickname, $iDelay = 500000)
	{
		$aMatches = $this->getRequest("WHOIS {$sNickname}", array('311', '312', '318', '319'), $iDelay);
		$aReturn = array();
		$aReturn['CHANNELS'] = array();
	
		foreach($aMatches as $sMatch)
		{
			$aTemp = explode(' ', $sMatch, 5);
			$aTemp[4] = trim($aTemp[4]);
			
			switch($aTemp[1])
			{
				case '311':
				{
					$aChunks = explode(' ', $aTemp[4], 4);
					$aReturn['INFO'] = array
					(
						"USERNAME" => $aChunks[0],
						"HOSTNAME" => $aChunks[1],
						"REALNAME" => substr($aChunks[3], 1),
					);
					break;
				}
				case '312':
				{
					$aChunks = explode(' ', $aTemp[4], 2);
					$aReturn['SERVER'] = array
					(
						"SERVER" => $aChunks[0],
						"INFO" => substr($aChunks[1], 1),
					);
					break;
				}
				case '318':
				{
					break;
				}
				case '319':
				{
					$aReturn['CHANNELS'] = array_merge($aReturn['CHANNELS'], explode(' ', substr($aTemp[4], 1)));
					break;
				}
			}
		}

		return $aReturn;
	}
	
	
	/**
	 *	Returns 
	 */
	public function getWhoList($sChannel, $iDelay = 500000)
	{
	}
	
	
	/**
	 *	Send an inter-bot-communication message to a bot-group. It will
	 *	remain in the queue until it is retrieved from the stack.
	 *
	 *	<code>$this->sendIBCMessage('OUTRAGEbot', array('This', 'is', 'an', 'array'));</code>
	 *
	 *	@param string $sBotGroup Bot group to send the message to.
	 *	@param mixed $mContents Thing to put into the stack.
	 *	@param string $sChannel Channel name
	 */
	public function sendIBCMessage($sBotGroup, $mContents, $sChannel = "Default")
	{
		Control::$aStack[$sBotGroup][$sChannel][] = $mContents;
		return true;
	}
	
	
	/**
	 *	Recieve all inter-bot-communication messages that are in the
	 *	stack for this particular bot.
	 *
	 *	<code>$aQueue = $this->getIBCMessages();</code>
	 *
	 *	@param string $sChannel Channel name
	 *	@return array Array of all messages.
	 */
	public function getIBCMessages($sChannel = "Default")
	{
		$aResult = Control::$aStack[$this->sBotGroup][$sChannel];
		Control::$aStack[$this->sBotGroup][$sChannel] = array();
		
		return $aResult;
	}
	
	
	/**
	 *	Counts the amount of messages in the stack for the current
	 *	bot group.
	 *
	 *	@param string $sChannel Channel name
	 *	@return integer Amount of messages in the stack.
	 */
	public function getIBCCount($sChannel = "Default")
	{
		if(isset(Control::$aStack[$this->sBotGroup][$sChannel]))
		{
			return count(Control::$aStack[$this->sBotGroup][$sChannel]);
		}
		
		return 0;
	}
	
	
	/**
	 *	Function to get the date since something.
	 *
	 *	@ignore
	 */
	public function dateSince($iDate1, $iDate2 = 0)
	{
		if(!$iDate2)
		{
			$iDate2 = mktime();
		}

   		$aDifferences = array
		(
			'SECONDS' => 0,
			'MINUTES'=> 0,
			'HOURS' => 0,
			'DAYS' => 0,
			'WEEKS' => 0,
			
			'TOTAL_SECONDS' => 0,
			'TOTAL_MINUTES' => 0,
			'TOTAL_HOURS' => 0,
			'TOTAL_DAYS' => 0,
			'TOTAL_WEEKS' => 0,
		);

		if($iDate2 > $iDate1)
		{
			$iTemp = $iDate2 - $iDate1;
		}
		else
		{
			$iTemp = $iDate1 - $iDate2;
		}

		$iSeconds = $iTemp;

		$aDifferences['WEEKS'] = floor($iTemp / 604800);
		$iTemp -= $aDifferences['WEEKS'] * 604800;

		$aDifferences['DAYS'] = floor($iTemp / 86400);
		$iTemp -= $aDifferences['DAYS'] * 86400;

		$aDifferences['HOURS'] = floor($iTemp / 3600);
		$iTemp -= $aDifferences['HOURS'] * 3600;

		$aDifferences['MINUTES'] = floor($iTemp / 60);
		$iTemp -= $aDifferences['MINUTES'] * 60;

		$aDifferences['SECONDS'] = $iTemp;
		
		$aDifferences['TOTAL_WEEKS'] = floor($seconds/604800);
		$aDifferences['TOTAL_DAYS'] = floor($seconds/86400);
		$aDifferences['TOTAL_HOURS'] = floor($seconds/3600);
		$aDifferences['TOTAL_MINUTES'] = floor($seconds/60);
		$aDifferences['TOTAL_SECONDS'] =$iSeconds;

		return $aDifferences;
	}
}

