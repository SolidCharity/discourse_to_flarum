<?php

// ==========================================================
// || Original script by robrotheram from discuss.flarum.org
// ||
// || Modified by VIRUXE
// || And Reflic
// || And TBits.net
// ||
// || Modified and configuration support
// || added by Crusader99
// ==========================================================

main();

function main() {
	echo "Initializing script...\n";

	// Put some settings
	set_time_limit(0);
	ini_set('memory_limit', -1);
	ini_set("log_errors", 1);
	ini_set("error_log", "php-error.log");

	// Load configuration file
	echo "Read configuration...\n";
	$config = yaml_parse_file("migrate.yaml");

	// Read database credentials from configuration
	$old_auth = $config['authentification']['old-database'];
	$new_auth = $config['authentification']['new-database'];

	// Run all steps for forum migration...
	echo "Run steps...\n";
	$step_count = 0;
	foreach ($config['steps'] as $step) {
	        $step_count++;
	        nextMigrationStep($step_count, $old_auth, $new_auth, $step);
	}

	// Forum migration has finished
	echo "\n---\n\nMigration completely done! :D\n\n";
}

// Do one step of the forum migration
function nextMigrationStep($step_count, $old_auth, $new_auth, $config_part) {

	// Check if this step is enabled
	$enabled = $config_part['enabled'];
	if ($enabled == false) {
		return;
	}

	// Get the names of the tables
        $old_table_name = $config_part['old-table'];
	$new_table_name = $config_part['new-table'];

	// Get the action type
	$action = $config_part['action'];
	
	if ($action == "COPY") {
       		$old_table_name = $config_part['old-table'];
       		$new_table_name = $config_part['new-table'];
		$convert_data = $config_part['columns'];
		copyItemsToExportDatabase($step_count, $old_auth, $new_auth, $old_table_name, $new_table_name, $convert_data);
	} else if ($action == "RUN_COMMAND") {
                $command = $config_part['command'];
                runExportSqlCommand($step_count, $old_auth, $new_auth, $command);
	} else {
		// Other action types are currently not supported
                echo "Unknown action type: $action";
	}
}	

// Copy-step, required by the do_step method.
function copyItemsToExportDatabase($step_count, $old_auth, $new_auth, $old_db_name, $new_db_name, $convert_data) {

	// Credentials of the databases
	$exporthost = $old_auth['host'];
	$exportusername = $old_auth['user'];
	$exportpassword = $old_auth['password'];
	$exportDBName = $old_auth['name'];

	$importhost = $new_auth['host'];
	$importusername = $new_auth['user'];
	$importpassword = $new_auth['password'];
	$importDBName = $new_auth['name'];

	// Print some info of this operation
	echo "\n\nStep-$step_count, starting copy-task with the following settings:\n";
	foreach ($convert_data as $key => $value) {
		echo "$old_db_name." . getKey($key) . " -> $new_db_name.$value\n";
	}
	echo "\nPress 'ENTER' to start.";
	
	// Wait for some keyboard input
	readInputLine();

	// Connecting to database...

	echo "Connecting to database '$exportDBName'...\n";
	// Establish a connection to the server where the PHPBB database exists
	$exportDbConnection =  pg_connect("host=/var/run/postgresql port=5432 dbname=$exportDBName user=$exportusername password=$exportpassword");
	echo "Ok, checking network state...\n";
	// Check connection
	if ($exportDbConnection === false) {
		die("Export - Connection failed: " . $exportDbConnection->connect_error);
	} else {
		echo "Export - Connected successfully\n";

		pg_set_client_encoding($exportDbConnection, UNICODE);
		printf("Current character set: %s\n", pg_client_encoding($exportDbConnection));
	}
	
	// Establish a connection to the server where the Flarum database exists
	$importDbConnection = new mysqli($importhost, $importusername, $importpassword, $importDBName);
	
	// Check connection
	if ($importDbConnection->connect_error) {
		die("Import - Connection failed: " . $importDbConnection->connect_error);
	} else {
		// If connected successfully...
		echo "Import - Connected successfully\n";
	
		if (!$importDbConnection->set_charset("utf8")) {
		    printf("Error loading character set utf8: %s\n", $importDbConnection->error);
		    exit();
		} else {
		    printf("Current character set: %s\n", $importDbConnection->character_set_name());
		}
	}
	
	// Convert & copy data
	echo "Converting...\n";

	// Create sql-command for the old database...
	$sql = "SELECT ";
	$counter = 0;
	foreach ($convert_data as $key => $value) {
		$theKey = getKey($key);
		if (empty($theKey)) {
			continue;
		}

		if ($counter != 0) {
			$sql = "$sql, ";
		}
		$sql = "$sql" . $theKey;
		$counter++;
	}
	$sql = "$sql FROM $old_db_name";
	
	// Run generated sql-command
	$result = pg_query($exportDbConnection, $sql);
	if (!$result) {
		die("Error with SQL query:\n $sql\n");
	}
	if (pg_num_rows($result)) {
	
		// Start copy operation if everything is okay
		$data_total = 0;
		$data_success = 0;
	
		// For each item in table
		while ($row = pg_fetch_assoc($result)) {
			$data_total++;
	
			// Create sql-command for the export database
			$query = "INSERT INTO " . $new_db_name . " ( ";
			$counter = 0;
			foreach ($convert_data as $key => $value) {
				if ($counter != 0) {
					$query = "$query, ";
				}
	
	        		$query = "$query $value";
				$counter++;
			}

			// Add the values to the sql-command
			$query = "$query ) VALUES (";	
			$counter = 0;
	                foreach ($convert_data as $key => $value) {
	                        if ($counter != 0) {
	                                $query = "$query, ";
	                        }
	
				$new_data_value = getValue($key, $importDbConnection, $row);
	                        $query = "$query'$new_data_value'";
				$counter++;
	                }
			$query = "$query )";
	
			// Run generated sql-command on export database...
			echo "Importing new item... ($data_total)\n";
			$res = $importDbConnection->query($query);
			if ($res === false) {
				echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . "\n";
			} else {
				echo "Done.\n";
                    		$data_success++;
			}
		}

		// If this operation has finished
		echo "\n---\n\n$data_success" . ' out of '. $data_total .' total items converted.'."\n";
	} else {
		echo "Something went wrong. :/";
	}

	// Close connection to the database
	pg_close($exportDbConnection);
	$importDbConnection->close();
}

// This method runs a single operation on the export database
function runExportSqlCommand($step_count, $old_auth, $new_auth, $sql_command) {
        $exporthost = $old_auth['host'];
        $exportusername = $old_auth['user'];
        $exportpassword = $old_auth['password'];
        $exportDBName = $old_auth['name'];

        $importhost = $new_auth['host'];
        $importusername = $new_auth['user'];
        $importpassword = $new_auth['password'];
        $importDBName = $new_auth['name'];

	// Print some details of this operation
        echo "\n\nStep-$step_count, running following sql-command:\n";
	echo "> $sql_command\n";
        echo "\nPress 'ENTER' to start.\n";

	// Wait for some input on the keyboard
        readInputLine();

        echo "Connecting to database '$exportDBName'...\n";

        // Establish a connection to the server where the Flarum database exists
        $importDbConnection = new mysqli($importhost, $importusername, $importpassword, $importDBName);

        // Check connection
        if ($importDbConnection->connect_error) {
                die("Connection failed: " . $importDbConnection->connect_error);
        } else {
                echo "Connected successfully\n";

                if (!$importDbConnection->set_charset("utf8")) {
                    printf("Error loading character set utf8: %s\n", $importDbConnection->error);
                    exit();
                } else {
                    printf("Current character set: %s\n", $importDbConnection->character_set_name());
		}
        }

	// Executing sql-command
        echo "Typing command...\n";
        $res = $importDbConnection->query($sql_command);

	// Check success
        if ($res === false) {
        	echo "Wrong SQL: " . $sql_command . " Error: " . $importDbConnection->error . "\n";
        } else {
        	echo "Done.\n";
	}
}

// Removes any syntax elements and returns the raw key
function getKey($orginal) {
	return findFirstArg($orginal, array("?", "+", "-", "*"));
}

// Returns the new value, depends on the syntax elements
function getValue($orginal, $importDbConnection, $row) {
	$value = findValue($orginal, $importDbConnection, $row);

	// Increments the value by one if the syntax elements in the configuration want to have that
	if (endsWith($orginal, "++")) {
		$value++;
	} else if (endsWith($orginal, "--")) {
                $value--;
        }

	// Check if there is an expression
	$array = explode("?", $orginal);
        if (sizeof($array) == 2) {
		// The new value is "true", if the value from the old table equals to the expected value
                $value = $array[1] == $value;
        }
	
	return $value;
}

// Reads the correct value from the row and sets special flags if there are any defined in the configuration
function findValue($orginal, $importDbConnection, $row) {
	$theKey = getKey($orginal);

	if ($orginal == "rnd_color**") {
        	return randomColor();
        } else if (endsWith($orginal, "*")) {
        	return formatText($importDbConnection, $row[$theKey]);
	} else {	
		return addslashes($row[$theKey]);
	}
}

// Splits the given string by all characters in the array and returns the first argument
function findFirstArg($str, $array) {
	foreach ($array as $split) {
		$str = explode($split, $str)[0];
	}
	return $str;
}

// Starts-with method like in other programming languages
function startsWith($input, $check) {
	return (substr($input, 0, strlen($check)) === $required);
}

// Ends-with method like in other programming languages
function endsWith($input, $check) {
	return substr($input, -strlen($check)) === $check;
}

// Waits for input in the console
function readInputLine() {
        $handle = fopen ("php://stdin","r");
        $line = fgets($handle);
        return trim($line);
}

// Returns a random generated color
function randomColor() {
	return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}

// Formats PHPBB's text to Flarum's text format
function formatText($connection, $text) {
	$text = preg_replace('#\:\w+#', '', $text);
	$text = convertBBCodeToHTML($text);
	$text = str_replace("&quot;","\"",$text);
	$text = preg_replace('|[[\/\!]*?[^\[\]]*?]|si', '', $text);
	$text = trimSmileys($text);
        $text = fixUserLinks($text);
	$text = fixCodeHighlighting($text);

	// Wrap text lines with paragraph tags
	$explodedText = explode("\n", $text);
	foreach ($explodedText as $key => $value) {
		if (strlen($value) > 1) // Only wrap in a paragraph tag if the line has actual text
			$explodedText[$key] = '<p>' . $value . '</p>';
	}
	$text = implode("\n", $explodedText);

	$wrapTag = strpos($text, '&gt;') > 0 ? "r" : "t"; // Posts with quotes need to be 'richtext'
	$text = sprintf('<%s>%s</%s>', $wrapTag, $text, $wrapTag);
	return $connection->real_escape_string($text);
}

// This function is able to convert BBCode to HTML code
function convertBBCodeToHTML($bbcode) {
	$bbcode = preg_replace('#\[b](.+)\[\/b]#', "<b>$1</b>", $bbcode);
	$bbcode = preg_replace('#\[i](.+)\[\/i]#', "<i>$1</i>", $bbcode);
	$bbcode = preg_replace('#\[u](.+)\[\/u]#', "<u>$1</u>", $bbcode);

	$bbcode = preg_replace('#\[img](.+?)\[\/img]#is', "<img src='$1'\>", $bbcode);
	$bbcode = preg_replace('#\[quote=(.+?)](.+?)\[\/quote]#is', "<QUOTE><i>&gt;</i>$2</QUOTE>", $bbcode);
	$bbcode = preg_replace('#\[code:\w+](.+?)\[\/code:\w+]#is', "<CODE class='hljs'>$1<CODE>", $bbcode);
	$bbcode = preg_replace('#\[pre](.+?)\[\/pre]#is', "<code>$1<code>", $bbcode);
	$bbcode = preg_replace('#\[\*](.+?)\[\/\*]#is', "<li>$1</li>", $bbcode);
	$bbcode = preg_replace('#\[color=\#\w+](.+?)\[\/color]#is', "$1", $bbcode);
	$bbcode = preg_replace('#\[url=(.+?)](.+?)\[\/url]#is', "<a href='$1'>$2</a>", $bbcode);
	$bbcode = preg_replace('#\[url](.+?)\[\/url]#is', "<a href='$1'>$1</a>", $bbcode);
	$bbcode = preg_replace('#\[list](.+?)\[\/list]#is', "<ul>$1</ul>", $bbcode);

	$bbcode = preg_replace('#\[size=200](.+?)\[\/size]#is', "<h1>$1</h1>", $bbcode);
	$bbcode = preg_replace('#\[size=170](.+?)\[\/size]#is', "<h2>$1</h2>", $bbcode);
	$bbcode = preg_replace('#\[size=150](.+?)\[\/size]#is', "<h3>$1</h3>", $bbcode);
	$bbcode = preg_replace('#\[size=120](.+?)\[\/size]#is', "<h4>$1</h4>", $bbcode);
	$bbcode = preg_replace('#\[size=85](.+?)\[\/size]#is', "<h5>$1</h5>", $bbcode);

	return $bbcode;
}

// Changes the format of the smileys
function trimSmileys($postText) {
	$startStr = "<!--";
	$endStr = 'alt="';

	$startStr1 = '" title';
	$endStr1 = " -->";

	$emoticonsCount = substr_count($postText, '<img src="{SMILIES_PATH}');

	for ($i=0; $i < $emoticonsCount; $i++) {
		$startPos = strpos($postText, $startStr);
		$endPos = strpos($postText, $endStr);

		$postText = substr_replace($postText, NULL, $startPos, $endPos-$startPos+strlen($endStr));

		$startPos1 = strpos($postText, $startStr1);
		$endPos1 = strpos($postText, $endStr1);

		$postText = substr_replace($postText, NULL, $startPos1, $endPos1-$startPos1+strlen($endStr1));
	}

	return $postText;
}

// Tries to fix the old format () and convert it to the new one
function fixUserLinks($post) {
	try {
       		$result = "";
		$count = 0;
        	foreach (explode("@", $post) as $split) {
                        $count++;
			if($count == 1) {
				$result = $split;
				echo "set";
				continue;
			}

              		$lenUsername = getLengthOfUsername($split);
			if (sizeof($lenUsername) == 0) {
				continue;
			}
                	$username = substr($split, 0, $lenUsername);
			$plain = substr($split, $lenUsername);
			
			$result = "$result<USERMENTION id=\"-1\" username=\"$username\">@$username</USERMENTION>$plain";
	        }
		return $result;
	} catch(Exception $ex) {
		echo "Warning: An error occurred while trying to fix user links";
		return $post;
	}

}

// Returns the length of the user-name by the given text
function getLengthOfUsername($username) {
	$count = 0;
	$length = strlen($username);

	//Count until there is an invalid character in the text
	for ($i=0; $i<$length; $i++) {
		$chr = $username[$i];
		if ($chr >= 'a' && $chr <= 'z' || $chr >= 'A' && $chr <= 'Z' || $chr >= '0' && $chr <= '9' || $chr == '_') {
			$count++;
		} else {
			break;
		}
	}
	return $count;
}

// This function will replace the code highlighting from the old forum format to the new one
function fixCodeHighlighting($post) {
	// For single line markdowns
	$post = preg_replace("/<code(.*?)>/is", "<C><s>`</s>", $post);
        $post = str_replace("</code>", "<e>`</e></C>", $post);
	
	// For code blocks with multiple line
	$array = explode("```", $post);
	$count = 0;
	$result = "";
	foreach ($array as $split) {
		try {
			if ($count == 0 || $count + 1 == sizeof($array)) {
				$result = "$result$split";
				continue;
			}
			$result = "$result<CODE><s>```</s>$split<e>```</e></CODE>";
		} finally {
			$count++;
		}
	}
	return $result; 
}

?>

