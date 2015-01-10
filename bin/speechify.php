<?php
# Listenability Speechification Script
# USAGE: php speechify.php --feed-url='http://example.org/feed/?listenability_status=needs-audio-enclosure' --audio-upload-key='123456789abcdef'
# You can also supply --rate and --voice params as defined by the say command.
# It is intended that this be installed as a cronjob.

if ( 'cli' !== php_sapi_name() ) {
	echo "Error: This script is to only be executed from the command line.\n";
	exit( 1 );
}

$args = getopt( '', array(
	'feed-url:',
	'audio-upload-key:',
	'voice:',
	'rate:',
) );

if ( empty( $args['feed-url'] ) ) {
	echo "Error: Missing --feed-url argument.\n";
	exit( 1 );
}
if ( empty( $args['audio-upload-key'] ) ) {
	echo "Error: Missing --audio-upload-key argument.\n";
	exit( 1 );
}

system( 'command -v say > /dev/null', $exit_code );
if ( 0 !== $exit_code ) {
	echo "Error: Unable to locate say command. Are you on a Mac?\n";
	die( $exit_code );
}

system( 'command -v /usr/local/bin/sox > /dev/null', $exit_code );
if ( 0 !== $exit_code ) {
	echo "Error: Please install sox.\n";
	die( $exit_code );
}

$feed_url = $args['feed-url'];
$audio_upload_key = $args['audio-upload-key'];

$say_args = array(
	'rate' => 250,
);
foreach ( array( 'rate', 'voice' ) as $key ) {
	if ( ! empty( $args[ $key ] ) ) {
		$say_args[ $key ] = $args[ $key ];
	}
}

echo "audio_upload_key = $audio_upload_key\n";
echo "Fetching feed $feed_url\n";
$xml = file_get_contents( $feed_url );
if ( empty( $xml ) ) {
	echo "HTTP failure\n";
	exit( 1 );
}

$doc = new DOMDocument();
$doc->loadXML( $xml );
$xpath = new DOMXPath( $doc );
$xpath->registerNamespace( 'listenability', 'http://listenability.org/ns' );
echo "\n";

foreach ( $xpath->query( '//item' ) as $item ) {
	$enclosure = $xpath->query( './enclosure[ @type = "text/plain+embedded-speech-commands" ]', $item )->item( 0 );
	if ( empty( $enclosure ) ) {
		continue;
	}
	$text_enclosure_url = $enclosure->getAttribute( 'url' );

	$title = $xpath->query( './/title', $item )->item( 0 );
	if ( $title ) {
		echo '## ' . $title->textContent . "\n";
	}

	$audio_url_attr = $xpath->query( './/listenability:audio/@href', $item )->item( 0 );
	if ( ! $audio_url_attr ) {
		continue;
	}
	$audio_url = $audio_url_attr->nodeValue;

	echo "Fetching speech text from $text_enclosure_url\n";
	$speech_text = file_get_contents( $text_enclosure_url );
	if ( empty( $speech_text ) ) {
		continue;
	}

	$text_file = tempnam( '/tmp', 'listenability' ) . '.txt';
	$audio_file = tempnam( '/tmp', 'listenability' ) . '.aiff';
	$mp3_file = tempnam( '/tmp', 'listenability' ) . '.aiff.mp3';
	file_put_contents( $text_file, $speech_text );

	$options = array_merge(
		$say_args,
		array(
			'input-file' => $text_file,
			'output-file' => $audio_file,
		)
	);
	$cmd = 'say';
	foreach ( $options as $key => $value ) {
		$cmd .= sprintf( ' --%s=%s', $key, escapeshellarg( $value ) );
	}

	echo "Creating initial audio via: $cmd\n";
	system( $cmd, $exit_code );
	if ( 0 !== $exit_code ) {
		echo "Error: Failed to generate audio.\n";
		exit( $exit_code );
	}

	// @todo Let the sox path be a parameter.
	$cmd = sprintf( '/usr/local/bin/sox %s %s', $audio_file, $mp3_file );
	echo "Converting audio to MP3 via: $cmd\n";
	system( $cmd, $exit_code );
	if ( 0 !== $exit_code ) {
		echo "Error: Failed to convert to mp3.\n";
		exit( $exit_code );
	}

	echo "MP3 generated: $mp3_file\n";

	echo "Uploading to podcast\n";
	$request = curl_init( $audio_url );
	curl_setopt( $request, CURLOPT_POST, true );
	curl_setopt(
		$request,
		CURLOPT_POSTFIELDS,
		array(
			'audio_file' => '@' . $mp3_file,
			'audio_upload_key' => $audio_upload_key,
		)
	);
	curl_setopt( $request, CURLOPT_RETURNTRANSFER, true );
	echo curl_exec( $request );
	curl_close( $request );
	echo "\n\n";
}

echo "Done\n";
