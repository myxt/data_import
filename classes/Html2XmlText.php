<?php

class XmlTextParser
{
	
	private $error_messages;
	
	function __construct()
	{}
	
	// Use eZ Publish parser to translate a given HMTL to XMLTEXT syntax
	function Html2XmlText( $html )
	{
		$return = null;
		$this->error_messages = null;
		
		// clean up given input
		if ( extension_loaded( 'tidy' ) )
		{
			$tidy = new tidy();
			$config = array( 'doctype' => 'omit',
			                 'show-body-only' => true,
			                 'wrap' => false,
			                 'preserve-entities' => true,
			                 'new-inline-tags' => 'anchor,literal,dm_link,inline_image'
			);

			$tidy->parseString( $html, $config, 'UTF8');
			$tidy->cleanRepair();

			$html = tidy_get_output( $tidy );
		}

		
		
		// remove unsupported tags
		$html = strip_tags( $html, '<p><a><i><em><h><br><h1><h2><h3><h4><h5><h6><anchor><strong><literal><li><ul><ol><th><td><tr><table><embed>' );
		// remove tag attributes. Some might break the parser like <ul class='standard'>
		$html = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i",'<$1$2>', $html);
		// convert html entities like &euml; to utf8
		$html = html_entity_decode( $html );
		$parser = new eZSimplifiedXMLInputParser( null );
		$document = $parser->process( $html );

		# Handle errors
		if( $document === false )
		{
			$this->error_messages = $parser->getMessages();

			$return = false;
		}
		else
		{
			// Dom 2 XML string
			$return = eZXMLTextType::domString( $document );
		}
		
		return $return;
	}

	function get_error_messages()
	{
		return $this->error_messages;
	}

}

/*
 * 			//reset parser and write an error message in the xml text field


 */
?>