<?php

$_TEMPLATE["cisco.nx"] = array(
	"cmd" => "ssh -q -oStrictHostKeyChecking=no $auth[1]@$address",
	"cases" => array(
		array(
			array(".*@.*'s [Pp]assword:", "sshpassword", EXP_REGEXP),
			array("^[Uu]sername:", "user", EXP_REGEXP),
			array("^[Pp]assword:", "password", EXP_REGEXP),
			array("* >", "enable", EXP_GLOB),
			array("^.*[-_\.0-9A-Za-z]+#", "prompt", EXP_REGEXP, "jump")
		),
		array(
			array("^.*show run.*$", "show run", EXP_REGEXP),
			// array("^[\010]+[\x20h]+[\010]+", "chr", EXP_REGEXP), // Backspace-Space-Backspace
			array("*\n", "save", EXP_GLOB),
			array("*--More--*", "more", EXP_GLOB),
			array("^.*[-_\.0-9A-Za-z]+#", "exit", EXP_REGEXP, "finish")
		)
	),
	"answers" => array(
		array(
			array("sshpassword", "$auth[2]\n", 3),
			array("user", "$auth[1]\n", 1),
			array("password", "$auth[2]\n", 1),
			array("enable", "enable\n", 1),
			array("password", (isset($auth[3]) ? $auth[3] . "\n" : ""), 1), // Enabled password
			array("prompt", "show run\n", 1)
		),
		array(
			array("show run", "", 2),
			array("more", " ", -1),
			array("exit", "exit\n", 1)
		)
	)
);

