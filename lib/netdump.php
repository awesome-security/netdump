<?php

/*
 *    libnetdump.php 
 *    Copyright (C) 2017  Olaf Reitmaier Veracierta
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *		along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

define("NETDUMP_EOF", 0);         		// End of expect stream
define("NETDUMP_FULLBUFFER", 10); 		// Buffer stream is full 
define("NETDUMP_TIMEOUT", 20);    		// Input timeout error
define("NETDUMP_FINISHED", 30);  			// Completed and finished
define("NETDUMP_UNKNOWN_CASE", 30);   // Unknown case for match

function readlines($file, $skip = "#"){
	$lines = array();
	$handle = fopen($file, "r");
	while (($line = fgets($handle)) !== false){
		if ($line[0]!="#") $lines[] = $line;
	}
	fclose($handle);
	return $lines;
}

function splitlines($lines, $delimiter){
	$array = array(); 
	foreach($lines as $line){
  	$array[] = explode($delimiter, trim($line));
	}
	return $array;
}

function tabulate($array, $headers = NULL){
	$output_table = new Console_Table();
	if (!is_null($headers)) $output_table->setHeaders($headers);
	foreach($array as $row) $output_table->addRow($row);
	return $output_table->getTable();
}

function tabget($array, $index, $value){
	$found = NULL;
	foreach($array as $row){
		if ($row[$index] == $value){
			$found = $row;
			break;
		}
	}
	return $found;
}
				
function strgetchr($str, $to_string = true){
	$chars = array();
	foreach(str_split($str) as $char) $chars[] = 'chr(' . ord($char) . ')';
	if ($to_string){
		return implode(", ", $chars);
	}else{
		return $chars;
	}
}

function automata_netdump($cmd, $cases_groups, $answers_groups, $outfile){
	global $_DEBUG, $_COLORS;
	$outstream = fopen($outfile, "w+"); // Where to save output stream
	$stream = expect_popen($cmd); // Command input/ouput stream
	for($iteration = 0; $iteration < count($cases_groups); $iteration++)
	{
		$cases = $cases_groups[$iteration];  // expr | case name | expr type | jump next group?
		$answers = $answers_groups[$iteration]; // case name | answer | times -1, 0, 1, ...?, 
		if ($_DEBUG) echo colorDebug("iteration: $iteration");
		while (true) 
		{
			if ($_DEBUG) echo colorDebug("expect");
			$matchs = array(); // stream input matches 0, 1, 2, ... 8
			$casename = expect_expectl($stream, $cases, $matchs); // group of cases
			$matched = isset($matchs[0]) ? $matchs[0] : ""; // whole input which matched the case
			$case = tabget($cases, 1, $casename);
			if ($casename == "chr")
			{ 
				// Print characters ASCII codes & skip
				if ($_DEBUG) echo colorDebug("chr ->' ") . strgetchr($matched) . "'\n";
				continue;
			}
			if ($casename == "skip")
			{ 
				// Always skip
				if ($_DEBUG) echo "skip -> '" . $matched . "'\n";	
				continue;
			}
			if ($casename == "save")
			{ 
				// Save input to file
				$written = 0;
				if (strlen($matched)>0) $written = fwrite($outstream, $matched); // Save match (buffer)
				if ($_DEBUG) echo colorDebug("save [$written] -> ") . $matched . "\n";
				continue;	
			}
			$answered = false;
			for($i=0; $i<count($answers); $i++)
			{
				if ($answers[$i][0] == $casename)
				{
					// When -1 means this answer can be used always
					if ($answers[$i][2] == 0) continue; // Answers can't be used anymore
					if ($answers[$i][2] > 0) --$answers[$i][2]; // Use this answer again (n-times)
					if ($_DEBUG) echo colorDebug("answer (match) -> ")  . $matched . "\n";	
					if (empty($answers[$i][1]))
					{ 
						// Skip once, n-times or always (do nothing)
						if ($_DEBUG) echo colorDebug("answer (skip)");	
					}else{
						// Answer
						fwrite($stream, $answers[$i][1]); // Answers ...
						if ($_DEBUG) echo colorDebug("answer <- ") . $answers[$i][1] . "\n";
					}
					$answered = true;
					break;
				}
			}
			if ($answered)
			{	
				// Jump to next group of cases or finish?
				if (isset($case[3]) && in_array(isset($case[3]), array("jump", "finish")))
				{
					if ($_DEBUG) echo colorInfo($case[3]);
					if ($case[3] == "jump"){
						break 1; // Continue with the next group of cases (break while)
					}else{
						break 2; // Do not process more group of cases (break for)
					}
				}
			}
			else
			{ 
				// EOF, Timeout, Full buffer, Unknown...
				if ($_DEBUG)
				{
					switch($casename)
					{
						case EXP_EOF:
							echo colorWarn("eof");
							break;
						case EXP_TIMEOUT:
							echo colorError("timeout");
							break;
						case EXP_FULLBUFFER:
							echo colorError("fullbuffer");
							break;
						default:
							echo colorError("unknown case '$casename' -> ") . strgetchr($matched);				
							break;
					}
				}
				break 2; // Do not process more group of cases (break for)
			}
		}
	}
	fclose($stream);
	fclose($outstream);
	switch($casename){
		case EXP_EOF:
			$result = NETDUMP_EOF;
			break;
		case EXP_TIMEOUT:
			$result = NETDUMP_TIMEOUT;
			break;
		case EXP_FULLBUFFER:
		  $result = NETDUMP_FULLBUFFER;
			break;
		case "finish":
		  $result = NETDUMP_FINISHED;
			break;
		default:
			$result = NETDUMP_UNKNOWN_CASE;
			break;
	}
	return $result;
}

function automata_fortigate($type, $address, $user, $password, $outfile) {
	global $_DEBUG;
	if ($type == "fortigate") $infile = "fgt-config";
	if ($type == "fortigate-sfg") $infile = "sys_config";
	$cmd = "scp -q -oStrictHostKeyChecking=no $user@$address:$infile $outfile";
	if ($_DEBUG) echo "CMD: " . $cmd . "\n";
	$cases = array(	
		array(
			array("password:", "password", EXP_GLOB),
		)
	);
	$answers = array(
		array(
			array("password", "$password\n", 1)
		)
	);
	return automata_netdump($cmd, $cases, $answers, $outfile);
}

function automata_cisco($type, $address, $user, $password, $passwordEnable, $outfile) {
	global $_DEBUG;
	if ($type == "cisco-telnet"){
		$cmd = "telnet $address";
	}else{
		$cmd = "ssh -q -oStrictHostKeyChecking=no $user@$address";
	}
	if ($_DEBUG) echo "CMD: " . $cmd . "\n";
	$cases = array(
		array(
			array(".*@.*'s [Pp]assword:", "sshpassword", EXP_REGEXP),
			array("^[Uu]sername:", "user", EXP_REGEXP),
			array("^[Pp]assword:", "password", EXP_REGEXP),
			array("* >", "enable", EXP_GLOB),
			array("^.*[-_\.0-9A-Za-z]+#", "prompt", EXP_REGEXP, "jump"),
		),
		array(
			array("show run", "show run", EXP_GLOB),
			array("Building configuration...", "skip", EXP_GLOB),
			array("^[\010]+[\x20h]+[\010]+", "chr", EXP_REGEXP), // Backspace-Space-Backspace
			array("*\n", "save", EXP_GLOB),
			array("*--More--*", "more", EXP_GLOB),
			array("^[-_\.0-9A-Za-z]+#$", "exit", EXP_REGEXP, "finish"),
		)
	);
	$answers = array(
		array(
			array("sshpassword", "$password\n", 3),
			array("user", "$user\n", 1),
			array("password", "$password\n", 1),
			array("enable", "enable\n", 1),
			array("password", "$passwordEnable\n", 1),
			array("prompt", "show run\n", 1),
		),
		array(
			array("show run", "", 1),
			array("more", " ", -1),
			array("exit", "exit\n", 1)
		)
	);
	return automata_netdump($cmd, $cases, $answers, $outfile);
}

function help(){
	global $_TARGETS_FILE;
	global $_AUTHS_FILE;
	global $_OUTFILE_ROOTDIR;
	global $_LOGFILE_ROOTDIR;

			echo 
"
COMMANDS

php netdump.php [help]
	Shows this help
php netdump.php show targets
	List targets from file '$_TARGETS_FILE'
php netdump.php show auths
	List crendentials file '$_AUTHS_FILE'
php netdump.php show dump target [+/-days]
	List dumps for 'target' (case sensitive) created 'days' 
  before/after (+/-) somedays until today, using system 
  comands like: find, sort, etc.
php netdump.php run [tag]
php netdump.php debug [tag]

LOGGING

- Dumps are saved in: '$_OUTFILE_ROOTDIR'
- Logs are saved in: '$_LOGFILE_ROOTDIR'

\n";
	
}

function colorDebug($msg, $newline = "\n"){
	global $_COLORS;
	return $_COLORS->getColoredString($msg, "black", "magenta") . $newline;
}

function colorInfo($msg, $newline = "\n"){
	global $_COLORS;
	return $_COLORS->getColoredString($msg, "white", "blue") . $newline;
}

function colorOk($msg, $newline = "\n"){
	global $_COLORS;
	return $_COLORS->getColoredString($msg, "black", "green"). $newline;
}

function colorWarn($msg, $newline = "\n"){
	global $_COLORS;
	return $_COLORS->getColoredString($msg, "black", "yellow") . $newline;
}

function colorError($msg, $newline = "\n"){
	global $_COLORS;
	return $_COLORS->getColoredString($msg, "white", "red") . $newline;
}
