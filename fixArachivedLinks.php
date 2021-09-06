<?php

/**
 * This maintenance script replaces archive.org links that are still alive for live links
 */

require_once __DIR__ . '/w/maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class FixArchivedLinksScript extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Replace archive.org that are still alive, for live links' );
		$this->addOption( 'offset', '', false, true );
		$this->addOption( 'year', 'Only fix archive.org links from this year', false, true );
	}

	public function execute() {

		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbm = $lb->getConnectionRef( DB_MASTER );
		$res = $dbm->select( 'externallinks', [ 'el_from', 'el_to' ] );

		$offset = $this->getOption( 'offset', 0 );
		$year = $this->getOption( 'year', '' );
		foreach ( $res as $k => $row ) {
			if ( $k < $offset ) continue;

			// Only process archived links
			$archived = $row->el_to;
			if ( ! preg_match( "@http://web\.archive\.org/web/$year\d+/(.+)@", $archived, $matches ) ) {
				continue;
			}

			// Output where we're at
			$url = $matches[1];
			$url = parse_url( $url, PHP_URL_SCHEME ) === null ? 'http://' . $url: $url;
			$this->output( $k . ' ' . $url );

			// Check the URL response code
			$curl = curl_init( $url );
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $curl, CURLOPT_NOBODY, true );
			curl_setopt( $curl, CURLOPT_TIMEOUT_MS, 9999 );
			curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT_MS, 9999 );
			curl_exec( $curl );
			$httpCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
			curl_close( $curl );
			$this->output( ' ' . $httpCode );
			if ( in_array( $httpCode, [ 0, 403, 404, 410 ] ) ) {
				$this->output( ' .. still dead' . PHP_EOL );
				continue;
			}

			// Get the content of the page
			$id = $row->el_from;
			$Title = Title::newFromID( $id );
			if ( ! $Title->exists() ) {
				$this->output( ' .. title does not exist' . PHP_EOL );
				continue;
			}
			$Page = WikiPage::factory( $Title );
			$Revision = $Page->getRevision();
			$Content = $Revision->getContent( Revision::RAW );
			if ( $Title->isRedirect() ) {
				$Title = $Content->getRedirectTarget();
				if ( ! $Title->exists() ) {
					$this->output( ' .. redirect target does not exist' . PHP_EOL );
					continue;
				}
				$Page = WikiPage::factory( $Title );
				$Revision = $Page->getRevision();
				$Content = $Revision->getContent( Revision::RAW );
			}
			$text = ContentHandler::getContentText( $Content );

			// Replace the archived link
			if ( strpos( $text, $archived ) === false ) {
				$this->output( ' .. URL not found in the wikitext' . PHP_EOL );
				continue;
			}
			$text = str_replace( $archived, $url, $text );

			// Save the page
			$Content = ContentHandler::makeContent( $text, $Title );
			$User = User::newSystemUser( 'Archived links script' );
			$Updater = $Page->newPageUpdater( $User );
			$Updater->setContent( 'main', $Content );
			$Updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Replace archived link for live version' ), EDIT_SUPPRESS_RC );

			$this->output( ' .. fixed!' . PHP_EOL );
		}
	}
}

$maintClass = FixArchivedLinksScript::class;
require_once RUN_MAINTENANCE_IF_MAIN;
