<?php

namespace Listenability;

$strip_embedded_speech_commands = ( 'without-embedded-speech-commands' === get_query_var( Plugin::QUERY_VAR ) );

if ( $strip_embedded_speech_commands ) {
	$content_type = 'text/plain';
} else {
	$content_type = Presentation::TEXT_CONTENT_TYPE;
}

header( 'Content-Type: ' . $content_type . '; charset=' . get_bloginfo( 'charset' ) );
while ( have_posts() ) {
	the_post();
	Plugin::$instance->presentation->the_content( compact( 'strip_embedded_speech_commands' ) );
}
