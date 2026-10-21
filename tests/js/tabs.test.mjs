import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const tabs = createRequire( import.meta.url )( '../../assets/js/tabs.js' );

const SLUGS = [ 'connections', 'mappings', 'gift-cards', 'sync', 'logs' ];

test( 'test_pick_tab_falls_back_to_first', () => {
	assert.equal( tabs.pickTab( SLUGS, '?tab=mappings' ), 'mappings' );
	assert.equal( tabs.pickTab( SLUGS, '?license=3&tab=logs&dashboard=4' ), 'logs' );
	assert.equal( tabs.pickTab( SLUGS, '?tab=zzz' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '?license=3' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '?tab=%3Cscript%3E' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '?tab=<script>' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '?tab=' ), 'connections' );
	assert.equal( tabs.pickTab( SLUGS, '?tab=MAPPINGS' ), 'connections', 'Case matters: slugs are lower case.' );
	assert.equal( tabs.pickTab( [], '?tab=x' ), '' );
	assert.equal( tabs.pickTab( SLUGS, undefined ), 'connections' );
} );

test( 'test_slugify_title_text', () => {
	assert.equal( tabs.slugify( 'Gift cards' ), 'gift-cards' );
	assert.equal( tabs.slugify( '  Sync   logs! ' ), 'sync-logs' );
	assert.equal( tabs.slugify( 'Connections' ), 'connections' );
	assert.equal( tabs.slugify( '' ), '' );
	assert.equal( tabs.slugify( undefined ), '' );
} );

test( 'test_with_tab_replaces_only_the_tab_and_keeps_the_hash', () => {
	assert.equal( tabs.withTab( 'https://x.test/app/?license=3&dashboard=4&tab=connections#brxe-ab12', 'logs' ), 'https://x.test/app/?license=3&dashboard=4&tab=logs#brxe-ab12' );
	assert.equal( tabs.withTab( 'https://x.test/app/?license=3', 'sync' ), 'https://x.test/app/?license=3&tab=sync' );
	assert.equal( tabs.withTab( 'https://x.test/app/', 'sync' ), 'https://x.test/app/?tab=sync' );
} );
