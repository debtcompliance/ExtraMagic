<?php
class ExtraMagic {

	private static $vars = array(
		'CURRENTUSER',
		'CURRENTPERSON',
		'CURRENTLANG',
		'CURRENTSKIN',
		'ARTICLEID',
		'IPADDRESS',
		'DOMAIN',
		'GUID',
		'USERPAGESELFEDITS'
	);

	public static function onRegistration() {
		global $wgExtensionFunctions;
		$wgExtensionFunctions[] = __CLASS__ . '::setup';
	}

	public static function setup() {
		global $wgParser;
		$wgParser->setFunctionHook( 'REQUEST', __CLASS__ . '::expandRequest', SFH_NO_HASH );
		$wgParser->setFunctionHook( 'COOKIE',  __CLASS__ . '::expandCookie', SFH_NO_HASH );
		$wgParser->setFunctionHook( 'USERID',  __CLASS__ . '::expandUserID', SFH_NO_HASH );
		$wgParser->setFunctionHook( 'IFGROUP', __CLASS__ . '::expandIfGroup' );
		$wgParser->setFunctionHook( 'IFUSES',  __CLASS__ . '::expandIfUses' );
		$wgParser->setFunctionHook( 'IFCAT',   __CLASS__ . '::expandIfCat' );
		$wgParser->setFunctionHook( 'PREV',    __CLASS__ . '::expandPrev' );
		$wgParser->setFunctionHook( 'NEXT',    __CLASS__ . '::expandNext' );
		$wgParser->setFunctionHook( 'OWNER',   __CLASS__ . '::expandOwner', SFH_NO_HASH );
	}

	public static function onLanguageGetMagic( &$magicWords, $langCode = null ) {
 
 		// Magic words
		foreach( self::$vars as $var ) {
			$magicWords[strtolower( $var )] = array( 1, $var );
		}

		// Parser functions
		$magicWords['REQUEST'] = array( 0, 'REQUEST' );
		$magicWords['COOKIE']  = array( 0, 'COOKIE' );
		$magicWords['USERID']  = array( 0, 'USERID' );
		$magicWords['IFGROUP'] = array( 0, 'IFGROUP' );
		$magicWords['IFUSES']  = array( 0, 'IFUSES' );
		$magicWords['IFCAT']   = array( 0, 'IFCAT' );
		$magicWords['PREV']    = array( 0, 'PREV' );
		$magicWords['NEXT']    = array( 0, 'NEXT' );
		$magicWords['OWNER']   = array( 0, 'OWNER' );

		return true;
	}

	public static function onMagicWordwgVariableIDs( &$variableIDs ) {
		foreach( self::$vars as $var ) $variableIDs[] = strtolower( $var );
		return true;
	}

	public static function onParserGetVariableValueVarCache( &$parser, &$varCache ) {
		global $wgUser, $wgTitle;

		// CURRENTUSER
		$varCache['currentuser'] = $wgUser->mName;

		// CURRENTPERSON:
		$varCache['currentperson'] = $wgUser->getRealName();

		// CURRENTLANG:
		$varCache['currentlang'] = $wgUser->getOption( 'language' );

		// CURRENTSKIN:
		$varCache['currentskin'] = $wgUser->getOption( 'skin' );

		// ARTICLEID:
		$varCache['articleid'] = is_object( $wgTitle ) ? $ret = $wgTitle->getArticleID() : 'NULL';

		// IPADDRESS:
		$varCache['ipaddress'] = array_key_exists( 'REMOTE_ADDR', $_SERVER ) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

		// DOMAIN:
		$varCache['domain'] = array_key_exists( 'SERVER_NAME', $_SERVER ) ? str_replace( 'www.', '', $_SERVER['SERVER_NAME'] ) : 'localhost';

		// GUID:
		$varCache['guid'] = strftime( '%Y%m%d', time() ) . '-' . substr( strtoupper( uniqid('', true) ), -5 );

		// USERPAGESELFEDITS
		$out = '';
		$dbr = wfGetDB( DB_SLAVE );
		$tbl = array( 'user', 'page', 'revision' );
		$cond = array(
			'user_name = page_title',
			'rev_page  = page_id',
			'rev_user  = user_id'
		);
		$res = $dbr->select( $tbl, 'user_name', $cond, __METHOD__, array( 'DISTINCT', 'ORDER BY' => 'user_name' ) );
		foreach( $res as $row ) {
			$title = Title::newFromText( $row->user_name, NS_USER );
			if( is_object( $title ) && $title->exists() ) $out .= "*[[User:{$row->user_name}|{$row->user_name}]]\n";
		}
		$varCache['userpageselfedits'] = $out;

		return true;
	}



	/**
	 * Expand parser functions
	 */
	public static function expandRequest( &$parser, $param, $default = '', $seperator = "\n" ) {
		$parser->disableCache();
		$val = array_key_exists( $param, $_REQUEST ) ? $_REQUEST[$param] : $default;
		if( is_array( $val ) ) $val = implode( $seperator, $val );
		return $val;
	}

	public static function expandCookie( &$parser, $param, $default = '' ) {
		$parser->disableCache();
		return array_key_exists( $param, $_COOKIE ) ? $_COOKIE[$param] : $default;
	}

	public static function expandUserID( &$parser, $param ) {
		if( $param ) {
			$col = strpos( $param, ' ' ) ? 'user_real_name' : 'user_name';
			$dbr = wfGetDB( DB_SLAVE );
			if( $row = $dbr->selectRow( 'user', array( 'user_id' ), array( $col => $param ) ) ) return $row->user_id;
		} else {
			global $wgUser;
			return $wgUser->getID();
		}
		return '';
	}

	public static function expandIfGroup( &$parser, $groups, $then, $else = '' ) {
		global $wgUser;
		$intersection = array_intersect( array_map( 'strtolower', explode( ',', $groups ) ), $wgUser->getEffectiveGroups() );
		return count( $intersection ) > 0 ? $then : $else;
	}

	public static function expandIfUses( &$parser, $tmpl, $then, $else = '' ) {
		global $wgTitle;
		$dbr  = wfGetDB( DB_SLAVE );
		$tmpl = $dbr->addQuotes( Title::newFromText( $tmpl )->getDBkey() );
		$id   = $wgTitle->getArticleID();
		return $dbr->selectRow( 'templatelinks', '1', "tl_from = $id AND tl_namespace = 10 AND tl_title = $tmpl" ) ? $then : $else;
	}

	public static function expandIfCat( &$parser, $cat, $then, $else = '' ) {
		global $wgTitle;
		$id   = $wgTitle->getArticleID();
		$dbr  = wfGetDB( DB_SLAVE );
		$cat  = $dbr->addQuotes( Title::newFromText( $cat )->getDBkey() );
		return $dbr->selectRow( 'categorylinks', '1', "cl_from = $id AND cl_to = $cat" ) ? $then : $else;
	}

	public static function expandNext( $parser, $list ) {
		return self::nextprev( $list, 1 );
	}
 
	public static function expandPrev( $parser, $list ) {
		return self::nextprev( $list, -1 );
	}
	
	public static function nextprev( $l, $j ) {
		global $wgTitle;
		$r = '';
		$l = preg_replace( '|\s*\[\[.+|', '', $l ); // ensure there's no "further results" link on the end
		$l = explode( '#', $l );
		$i = array_search( $wgTitle->getPrefixedText(), $l );
		if( $i !== false && array_key_exists( $i+$j, $l ) ) $r = $l[$i+$j];
		return $r;
	}
	
	public static function expandOwner( $parser, $title ) {
		$owner = '';
		if( empty( $title ) ) {
			global $wgTitle;
			$title = $wgTitle;
		} else $title = Title::newFromText( $title );
		$id = $title->getArticleID();
		$dbr = wfGetDB( DB_SLAVE );
		if( $id > 0 && $row = $dbr->selectRow( 'revision', 'rev_user', array( 'rev_page' => $id ), __METHOD__, array( 'ORDER BY' => 'rev_timestamp' ) ) ) {
			$owner = User::newFromID( $row->rev_user )->getName();
		}
		return $owner;
	}
}

