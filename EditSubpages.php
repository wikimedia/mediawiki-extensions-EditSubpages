<?php

/**
* Allows sysops to unlock a page and all subpages of that page for anonymous editing
* via MediaWiki:Unlockedpages
*/

if( !defined( 'MEDIAWIKI' ) ) {
	echo 'This file is an extension to the MediaWiki software and cannot be used standalone';
	die( 1 );
}

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'EditSubpages',
	'descriptionmsg' => 'editsubpages-desc',
	'author' => array( '<span class="plainlinks\>[http://strategywiki.org/wiki/User:Ryan_Schmidt Ryan Schmidt]</span>', '<span class="plainlinks">[http://strategywiki.org/wiki/User:Prod Prod]</span>' ),
	'url' => 'https://www.mediawiki.org/wiki/Extension:EditSubpages',
	'version' => '3.1',
);

$wgHooks['userCan'][] = 'ExtEditSubpages::EditSubpages';
$wgGroupPermissions['*']['edit'] = true;
$wgGroupPermissions['*']['createpage'] = true;
$wgGroupPermissions['*']['createtalk'] = true;
$wgEditSubpagesDefaultFlags = '+scte-buinrw';
$dir = dirname(__FILE__) . '/';
$wgExtensionMessagesFiles['EditSubpages'] = $dir .'EditSubpages.i18n.php';
$evEditSubpagesCache = array();

class ExtEditSubpages {

public static function EditSubpages( $title, $user, $action, $result ) {
	global $evEditSubpagesCache;
	if( $title->getNamespace() < 0 ) {
		return true; //don't operate on "special" namespaces
	}
	$pagename = $title->getText(); //name of page w/ spaces, not underscores
	if( !array_key_exists( 'pagename', $evEditSubpagesCache ) || $pagename != $evEditSubpagesCache['pagename'] ) {
		$ns = $title->getNsText(); //namespace
		if( $title->isTalkPage() ) {
			$ns = $title->getTalkNsText();
			$nstalk = '';
		} else {
			$nstalk = $title->getTalkNsText();
		}
		if( $ns == '' ) {
			$text = $pagename;
		} else {
			$text = $ns . ":" . $pagename;
		}
		if( $nstalk != '' ) {
			$talktext = $nstalk . ":" . $pagename;
		} else {
			$talktext = $pagename;
		}
		//underscores -> spaces
		$ns = str_replace( '_', ' ', $ns );
		$nstalk = str_replace( '_', ' ', $nstalk );
		$pages = explode( "\n", wfMsg( 'unlockedpages' ) ); //grabs MediaWiki:Unlockedpages
		//cache the values so future checks on the same page take less time
		$evEditSubpagesCache = array(
			'pagename' => $pagename,
			'ns' => $ns,
			'nstalk' => $nstalk,
			'text' => $text,
			'talktext' => $talktext,
			'pages' => $pages,
			'loggedin' => $user->isLoggedIn(),
		);
	}
	if( ( $action == 'edit' || $action == 'submit' ) ){
		foreach( $evEditSubpagesCache['pages'] as $value ) {
			if( strpos( $value, '*' ) === false || strpos( $value, '*' ) !== 0 ) {
				continue; // "*" doesn't start the line, so treat it as a comment (aka skip over it)
			}
			global $wgEditSubpagesDefaultFlags;
			if( !is_array( $wgEditSubpagesDefaultFlags ) ) {
				$config_flags = self::parseFlags( $wgEditSubpagesDefaultFlags );
			} else {
				$config_flags = $wgEditSubpagesDefaultFlags;
			}
			//also hardcode the default flags just in case they are not set in $config_flags
			$default_flags = array( 's' => 1, 'c' => 1, 't' => 1, 'e' => 1, 'b' => 0, 'u' => 0, 'i' => 0, 'n' => 0, 'r' => 0, 'w' => 0 );
			$flags = array_merge( $default_flags, $config_flags );
			$value = trim( trim( trim( trim( $value ), "*[]" ) ), "*[]" );
			/* flags
			 * s = unlock subpages
			 * c = allow page creation
			 * t = unlock talk pages
			 * e = allow editing existing pages
			 * b = unlock base pages
			 * u = apply restrictions to users as well
			 * i = case insensitive
			 * n = namespace inspecific
			 * r = regex fragment
			 * w = wildcard matching
			*/
			$pieces = explode( '|', $value, 3 );
			if( isset( $pieces[1] ) ) {
				$flags = array_merge( $flags, self::parseFlags( $pieces[1] ) );
			}
			$found = self::checkPage( $pieces[0], $evEditSubpagesCache['text'], $flags );
			if( !$found && $flags['n'] ) {
				$found = self::checkPage( $pieces[0], $evEditSubpagesCache['pagename'], $flags );
			}
			if( !$found && $flags['t'] ) {
				$newtitle = Title::newFromText( $pieces[0] );
				//make sure that it's a valid title
				if( $newtitle instanceOf Title && !$newtitle->isTalkPage() ) {
					$talk = $newtitle->getTalkPage();
					$talkpage = $talk->getPrefixedText();
					$found = self::checkPage( $talkpage, $evEditSubpagesCache['talktext'], $flags );
					if( !$found ) {
						$found = self::checkPage( $talkpage, $evEditSubpagesCache['text'], $flags );
					}
				}
			}
			if( !$found ) {
				continue;
			}
				
			if( !$flags['u'] && $evEditSubpagesCache['loggedin'] ) {
				return true;
			}
			//the page matches, now process it and let the software know whether or not to allow the user to do this action
			if( !$flags['c'] && !$newtitle->exists() ) {
				$result = false;
				return false;
			}
			if( !$flags['e'] && $newtitle->exists() ) {
				$result = false;
				return false;
			}
			$result = true;
			return false;
		}
		if( !$evEditSubpagesCache['loggedin'] ) {
			$result = false;
			return false;
		}
	}
	return true;
}

/**
 * Parses a string of flags in the form +blah-blah (or -blah+blah, or +b+l+a+h-b-l-a-h, etc.) into an array
 * If a flag is encountered multiple times, the - will override the +, regardless of what position it was in originally
 * If no + or - prefixes a flag, it assumes that it is following the last seen + or -, if it is at the beginning, + is implied
 * @param $flags String of flags in +- format
 * @return array of flags with the flag letter as the key and boolean true or false as the value
 */
protected static function parseFlags( $flags_string = '' ) {
	$flags = array(
		'+' => array(),
		'-' => array()
	);
	$type = '+';
	$strflags = str_split( $flags_string );
	foreach( $strflags as $c ) {
		if( $c == '+' ) {
			$type = '+';
		} elseif( $c == '-' ) {
			$type = '-';
		} else {
			$flags[$type][$c] = ( $type == '+' ) ? true : false;
		}
	}
	return array_merge( $flags['+'], $flags['-'] );
}

protected static function checkPage( $page, $check, $flags ) {
	if( $flags['w'] && !$flags['r'] ) {
		$flags['r'] = 1;
		$page = preg_quote( $page, '/' );
		$page = str_replace( '\\\\', '\\', $page );
		$page = str_replace( '\?', '?', $page );
		$page = str_replace( '\*', '*', $page );
		$page = str_replace( '\?', "\x00", $page );
		$page = str_replace( '?', '.?', $page );
		$page = str_replace( "\x00", '\?', $page );
		$page = str_replace( '\*', "\x00", $page );
		$page = str_replace( '*', '.*', $page );
		$page = str_replace( "\x00", '\*', $page );
	}
	if( $flags['r'] ) {
		$i = '';
		if( $flags['i'] )
			$i = 'i';
		$page = preg_replace( '/(\\\\)?\//', '\\/', $page );
		return preg_match( '/^' . $page . '$/' . $i, $check );
	}
	if( $flags['i'] ) {
		$page = strtolower( $page );
		$check = strtolower( $check );
	}
	if( $page == $check ) {
		return true;
	}
	if( $flags['s'] && strpos( $check, $page . '/' ) === 0 ) {
		return true;
	}
	if( $flags['b'] && strpos( $page, $check . '/' ) === 0 ) {
		return true;
	}
	return false;
}

}