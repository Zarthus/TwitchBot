<?php
/**
 *	ConfigParser class for OUTRAGEbot
 *
 *	This class deals with the parsing of the bots.
 *
 *	This class loads the bots details from the INI file, then executes it. This enables the bot to have a very
 *	modular and easily modifiable structure. Due to the fact that this has moved from the rather redundant
 *	PHP based configuration, here is a sample (containing all the current values) of the new config file.
 *
 -	Yay, epic HTML formatting.
 *	<pre>
 *	<font color="#008000">; </font>
 *	<font color="#008000">; This header has a '~' suffix, which denotes that it is NOT</font>
 *	<font color="#008000">; an IRC bot, but rather a needed config file. (It must be named ~Network.)</font>
 *	<font color="#008000">; </font>
 *	
 *	<font color="4E009B"><b>[~Network]</b></font>
 *	<font color="#008000">; These keys are needed for the bot to operate.</font>
 *	name = FFSNetwork
 *	host = irc.ffsnetwork.com
 *	port = 6667
 *	owners = westie-cat.co.uk
 *	<font color="#008000">; These keys are optional, but are useful to have.</font>
 *	bind = 193.238.85.98
 *	channels = #westie, #channel2
 *	plugins = Evaluation, AutoInvite
 *	rotation = SEND_DIST
 *	quitmsg = A quit messag- wait, why are you reading this?
 *	delimiter = "!"
 *
 *	<font color="4E009B"><b>[OUTRAGEbot]</b></font>
 *	<font color="#008000">; </font>
 *	<font color="#008000">; This header doesn't have a '~' suffix, which denotes that it</font>
 *	<font color="#008000">; is an IRC bot. All three are needed.</font>
 *	<font color="#008000">; </font>
 *	altnick = OUTRAGEbot`
 *	username = testing
 *	realname = David Weston
 *	</pre>
 -	End of epic HTML formatting.
 *
 *	@package OUTRAGEbot
 *	@copyright David Weston (c) 2009 -> http://www.typefish.co.uk/licences/
 *	@author David Weston <westie@typefish.co.uk>
 *	@version 1.0
 */
 

class ConfigParser
{
	/**
	 *	Parses all of the /Configuration/ directory.
	 *
	 *	@ignore
	 */
	public function parseDirectory()
	{
		foreach(glob(BASE_DIRECTORY."/Configuration/*.ini") as $sGlob)
		{
			$this->parseConfigFile($sGlob);
		}		
	}
	
	
	/**
	 *	Parses a configuration file in /Configuration/. Note that $sConfig must not have an extension.
	 *
	 *	<code>Control::$oConfig->parseConfig("OUTRAGEbot"); // This loads /Configuration/OUTRAGEbot.ini</code>
	 *
	 *	@param string $sConfig Configuration filename
	 */
	public function parseConfig($sConfig)
	{
		if(file_exists(BASE_DIRECTORY."/Configuration/{$sConfig}.ini"))
		{
			$this->parseConfigFile(BASE_DIRECTORY."/Configuration/{$sConfig}.ini");
		}
	}
	
	
	/**
	 *	Parses a configuration file in any directory in the server (that it is possible to reach
	 *
	 *	@param string $sConfig Configuration file location
	 */
	public function parseConfigFile($sConfig)
	{
		$sName = substr(basename($sConfig), 0, -4);
		
		if($sName[0] == "~")
		{
			return;
		}
		
		$aConfig = parse_ini_file($sConfig, true);
		$oConfig = new stdClass();
		
		$oConfig->Network = $aConfig['~Network'];
		unset($aConfig['~Network']);
		
		foreach($aConfig as $sKey => $aBot)
		{
			$oConfig->Bots[$sKey] = $aBot;
			$oConfig->Bots[$sKey]['nickname'] = $sKey;
		}
		
		Control::$aBots[$sName] = new Master($sName, $oConfig);
	}
}