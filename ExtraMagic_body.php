<?php

use MediaWiki\MediaWikiServices;

class ExtraMagic {

	private static $vars = [
		'CURRENTUSER',
		'CURRENTPERSON',
		'CURRENTLANG',
		'CURRENTSKIN',
		'ARTICLEID',
		'IPADDRESS',
		'DOMAIN',
		'GUID',
		'USERPAGESELFEDITS'
	];

	public static function onRegistration() {
		global $wgExtensionFunctions;
		$wgExtensionFunctions[] = __CLASS__ . '::setup';
	}

	public static function setup() {
		$parser = \MediaWiki\MediaWikiServices::getInstance()->getParser();
		$parser->setFunctionHook( 'REQUEST', __CLASS__ . '::expandRequest', Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'COOKIE',  __CLASS__ . '::expandCookie', Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'USERID',  __CLASS__ . '::expandUserID', Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'IFGROUP', __CLASS__ . '::expandIfGroup' );
		$parser->setFunctionHook( 'IFUSES',  __CLASS__ . '::expandIfUses' );
		$parser->setFunctionHook( 'IFCAT',   __CLASS__ . '::expandIfCat' );
		$parser->setFunctionHook( 'PREV',    __CLASS__ . '::expandPrev' );
		$parser->setFunctionHook( 'NEXT',    __CLASS__ . '::expandNext' );
		$parser->setFunctionHook( 'OWNER',   __CLASS__ . '::expandOwner', Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'PRIVATE', __CLASS__ . '::expandPrivate', Parser::SFH_NO_HASH );
	}

	public static function onMagicWordwgVariableIDs( &$variableIDs ) {
		foreach( self::$vars as $var ) $variableIDs[] = 'MAG_' . $var;
		return true;
	}

	public static function onParserGetVariableValueSwitch( &$parser, &$cache, &$magicWordId, &$ret, &$frame ) {
		global $wgTitle;

		$user = RequestContext::getMain()->getUser();

		switch( $magicWordId ) {
			case 'MAG_CURRENTUSER':
				$val = $user->getName();
			break;

			case 'MAG_CURRENTPERSON':
				$val = $user->getRealName();
			break;

			case 'MAG_CURRENTLANG':
				$val = $user->getOption( 'language' );
			break;

			case 'MAG_CURRENTSKIN':
				$val = $user->getOption( 'skin' );
			break;

			case 'MAG_ARTICLEID':
				$val = is_object( $wgTitle ) ? $ret = $wgTitle->getArticleID() : 'NULL';
			break;

			case 'MAG_IPADDRESS':
				$val = array_key_exists( 'REMOTE_ADDR', $_SERVER ) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
			break;

			case 'MAG_DOMAIN':
				$val = array_key_exists( 'SERVER_NAME', $_SERVER ) ? str_replace( 'www.', '', $_SERVER['SERVER_NAME'] ) : 'localhost';
			break;

			case 'MAG_GUID':
				$val = strftime( '%Y%m%d', time() ) . '-' . substr( strtoupper( uniqid('', true) ), -5 );
			break;

			case 'MAG_USERPAGESELFEDITS':
				$out = '';
				$dbr = wfGetDB( DB_REPLICA );
				$tbl = [ 'actor', 'page', 'revision' ];
				$cond = [
					'actor_name = page_title',
					'rev_page   = page_id',
					'rev_actor  = actor_id'
				];
				$res = $dbr->select( $tbl, 'actor_name', $cond, __METHOD__, [ 'DISTINCT', 'ORDER BY' => 'actor_name' ] );
				foreach( $res as $row ) {
					$title = Title::newFromText( $row->actor_name, NS_USER );
					if( is_object( $title ) && $title->exists() ) $out .= "*[[User:{$row->actor_name}|{$row->actor_name}]]\n";
				}
				$val = $out;
			break;
		}

		// If a value was set (i.e. it's one of our magic words), disable the cache and set the return value
		if( isset( $val ) ) {
			$parser->disableCache();
			$ret = $val;
		}

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
			$dbr = wfGetDB( DB_REPLICA );
			if( $row = $dbr->selectRow( 'user', [ 'user_id' ], [ $col => $param ] ) ) return $row->user_id;
		} else {
			return RequestContext::getMain()->getUser()->getID();
		}
		return '';
	}

	public static function expandIfGroup( &$parser, $groups, $then, $else = '' ) {
		$user = RequestContext::getMain()->getUser();

		$intersection = array_intersect( array_map( 'strtolower', explode( ',', $groups ) ), $user->getEffectiveGroups() );
		return count( $intersection ) > 0 ? $then : $else;
	}

	public static function expandIfUses( &$parser, $tmpl, $then, $else = '' ) {
		global $wgTitle;
		$dbr  = wfGetDB( DB_REPLICA );
		$tmpl = $dbr->addQuotes( Title::newFromText( $tmpl )->getDBkey() );
		$id   = $wgTitle->getArticleID();
		return $dbr->selectRow( 'templatelinks', '1', "tl_from = $id AND tl_namespace = 10 AND tl_title = $tmpl" ) ? $then : $else;
	}

	public static function expandIfCat( &$parser, $cat, $then, $else = '' ) {
		global $wgTitle;
		$id   = $wgTitle->getArticleID();
		$dbr  = wfGetDB( DB_REPLICA );
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
		$dbr = wfGetDB( DB_REPLICA );
		if( $id > 0 && $row = $dbr->selectRow( 'revision', 'rev_actor', [ 'rev_page' => $id ], __METHOD__, [ 'ORDER BY' => 'rev_timestamp' ] ) ) {
			$owner = MediaWikiServices::getInstance()->getUserFactory()->newFromActorId( $row->rev_actor )->getName();
		}
		return $owner;
	}

	public static function expandPrivate( $parser, $val ) {
		global $wgPrivateData;

		$user = RequestContext::getMain()->getUser();

		if( !is_array( $wgPrivateData ) ) return "Error: No private data defined!";
		if( !array_key_exists( $val, $wgPrivateData ) ) return "Error: Private data \"$val\" not found!";
		$groups = array_map( 'strtolower', preg_split( '|\s*,\s*|', $wgPrivateData[$val][0] ) );
		$intersection = array_intersect( $groups, $user->getEffectiveGroups() );
		return count( $intersection ) > 0 ? [ $wgPrivateData[$val][1], 'isHTML' => true ] : '';
	}
}

