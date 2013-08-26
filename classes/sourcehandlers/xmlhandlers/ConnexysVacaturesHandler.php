<?php

/*
 * Versie 2.0
 */

include_once 'extension/data_import/classes/sourcehandlers/XmlHandler.php';

class ConnexysVacaturesHandler extends XmlHandlerPHP
{
	var $handlerTitle = 'Connexys Vacatures Handler v2';
	var $current_loc_info = array();
	var $logfile = 'connexys_vacatures_import.log';
	var $dataINI;
	var $remoteID = "";
	var $used_fields = array('vacaturenr',
                                                         'functiegroep_id1',
                                                         'functiegroep_id2',
                                                         'functiegroep_id3',
							 'titel',
							 'ondertitel',
							 'afdeling',
							 'divisie',
							 'salaris',
							 'min_uren',
							 'max_uren',
							 'bedrijfsinfo',
							 'functieomschrijving',
							 'functieeisen',
							 'arbeidsvoorwaarden',
							 'contactinfo',
							 'recruiternaam',
							 'functienivo',
							 'contracttype',
							 'contractduur',
							 'datum_vanaf',
							 'sluitingsdatum',
							 'sollicitatie_link',
							 'mail_a_friend_link');

	var $current_counter = 0;
	var $today;
	var $sourceIdArray = array();
	var $publicationID;
        
        var $categories = array();
        var $classCategories;
        
	var $publishVacancy = false;

	var $datum_van;
	var $datum_tot;
	var $sollicitatie_link;
	var $mail_a_friend_link;
        
        
	
	var $REMOTE_IDENTIFIER = 'xmlcv_v2_';

	function __construct() {
		$today = gmdate('d-m-Y');
		$this->today = $this->convertDayToTimestamp( $today );

		$this->dataINI = eZINI::instance( 'data_import.ini' );
		$this->publicationID = $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'xmlPublicationID' );
                
                $this->classCategories = $this->getClassCategories();
	}
        
        function getClassCategories()
        {
            $categories = array();
            
            $classIdentifier = $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'importClassIdentifier' );
            $class = eZContentClass::fetchByIdentifier($classIdentifier);
            $categoriesAttribute = $class->fetchAttributeByIdentifier('category');
            $xmlString = new SimpleXMLElement( $categoriesAttribute->attribute('data_text5') );
            foreach( $xmlString->options->option as $option )
            {
                $categories[(string) $option['identifier']] = (string) $option['name'];
                
            }
            
            return $categories;
        }
        
        function updateClassCategories( $contentObject )
        {
            $classCategories = $this->getClassCategories();
            foreach( $this->categories as $key => $category )
            {
                if( !isset( $classCategories[$key] ))
                {
                    $this->addClassCategory( $key, $category );
                }
            }
        }
        
        function addClassCategory( $identifier, $name )
        {
            $name = trim($name);
            $identifier = trim($identifier);
            
            if( $name !== '' && $identifier !== '' )
            {
                $classIdentifier = $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'importClassIdentifier' );
                $class = eZContentClass::fetchByIdentifier($classIdentifier);
                $categoriesAttribute = $class->fetchAttributeByIdentifier('category');
                $xmlString = new SimpleXMLElement( $categoriesAttribute->attribute('data_text5') );
                $count = count( $xmlString->options->option );

                $newOption = $xmlString->options->addChild('option');
                $newOption->addAttribute('id', $count++ );
                $newOption->addAttribute('identifier', $identifier );
                $newOption->addAttribute('name', $name );
                $newOption->addAttribute('priority', 1 );
                //print_r($xmlString->asXML());
                $categoriesAttribute->setAttribute( 'data_text5', $xmlString->asXML() );
                $categoriesAttribute->store();
                
                eZDebug::writeNotice( 'Added new vacancy category ' . $name .' to content class.' );
            }
        }

        /**
         * Adds a category to the current object. Some categories in the xml
         * should not be mapped but another category should be stored instead:
         *
         * - Human Resources (1) => Staf & Management (5)
         * - Marketing & Sales (6) => Staf & Management (5)
         * - Gezondheidszorg (21) => Medisch (63) en Verpleging & Verzorging (62)
         * - Verzorgende (42) = > Verpleging en Verzorging (62)
         *
         * @param type $identifier the Connexys identifier as found in the xml.
         * @param type $name  the Connexys name as found in the xml.
         */
        function addCategory( $identifier, $name )
        {
            $ini = eZINI::instance( 'data_import.ini' );
            $groupMap = $ini->variable( 'xmlhandler_connexysVacatures_v2', 'FunctiegroepToCategoryMap' );

            $name = trim($name);
            $identifier = trim($identifier);
            if( isset( $this->categories[$identifier] ) ||
                $name == '' ||
                $identifier == '' )
               return;

            if( isset( $groupMap[$identifier] ) )
                $categories = explode( ';', $groupMap[$identifier] );
            else
                $categories = array( $ini->variable( 'xmlhandler_connexysVacatures_v2', 'DefaultCategory' ) );
            
            foreach( $categories as $category )
                $this->categories[$category] = $category;
  
        }

	function getNextRow()
	{
                // Reset stuff.
		$this->first_field = true;
		$this->node_priority = false;
		$this->current_counter = 0;
                $this->categories = array();
		
		if( $this->first_row )
		{
			$this->first_row = false;
			$this->current_row = $this->data->firstChild;
		}
		else
		{
			$this->current_row = $this->current_row->nextSibling;
		}

		if( $this->current_row->nodeType != 1 ) //ignore xml #text nodes
		{
			$this->current_row = $this->getNextValidNode( $this->current_row );
		}
		return $this->current_row;
	}

	function getNextField()
	{
		if( $this->current_counter < count($this->used_fields) )
		{
			$nodeList = $this->current_row->getElementsByTagName($this->used_fields[$this->current_counter]);
			$this->current_field = $nodeList->item(0);
			$this->current_counter++;
			return $this->current_field;
		}
		else return false;
	}

	function geteZAttributeIdentifierFromField()
	{
            switch ( $this->current_field->tagName )
            {
                case 'functiegroep_id1':
                case 'functiegroep_id2':
                case 'functiegroep_id3':
                    return 'category';
                
                case 'datum_vanaf':
                    return 'publish_date';

                case 'sluitingsdatum':
                    return 'unpublish_date';

                default:
                    return $this->current_field->tagName;
            }
	}
	
	function getValueFromField()
	{
		switch( $this->current_field->tagName )
		{
                        case 'functiegroep_id1':
                        case 'functiegroep_id2':
                        case 'functiegroep_id3':
                        {
                            /* Kijk of deze categorie al bestaat in de enhanced
                               selection en voeg toe aan content class. */
                            $id = $this->current_field->nodeValue;
                            $value = $this->current_row->getElementsByTagName( implode( '_', explode( '_id', $this->current_field->tagName ) ) )->item(0)->nodeValue;
                            $this->addCategory( $id, $value );
                            return implode( ',', array_keys( $this->categories ) );
                            break;
                        }
                    
			case 'datum_vanaf':
			{
				return $this->datum_van;
				break;
			}
			case 'sluitingsdatum':
			{
				return $this->datum_tot;
				break;
			}

			case 'sollicitatie_link':
			{
				return $this->sollicitatie_link;
				break;
			}
			
			case 'mail_a_friend_link':
			{
				return $this->mail_a_friend_link;
				break;
			}
			
			default:
			{
				//return $this->fixEncoding($this->current_field->get_content());
				return $this->current_field->nodeValue; 
			}
		}

	}
	
	// logic where to place the current content node into the content tree
	function getParentNodeId()
	{
		$parent_id = $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'parentNodeID' ); // fallback bij creatie van nieuwe node
		
		$parent_remote_id = $this->current_row->getAttribute('parent_id');

		if( $parent_remote_id )
		{
			$eZ_object = eZContentObject::fetchByRemoteID( $this->REMOTE_IDENTIFIER.$parent_remote_id );

			if( $eZ_object )
			{
				$parent_id = $eZ_object->attribute('main_node_id');
			}
		}

		return $parent_id;
	}

	function getDataRowId()
	{
		return $this->REMOTE_IDENTIFIER.$this->current_row->getAttribute('id');
	}

	function getTargetContentClass()
	{
		return $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'importClassIdentifier' );
	}

	function readData()
	{
		$xml = $this->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'xmlDocument' );
		return $this->parse_xml_document( $xml, 'vacatures' ); //Skip <sitegegevens>
		//return $this->parse_xml_document( 'extension/data_import/dataSource/vacatures.xml', 'vacatures' );
	}
        
        function post_save_handling( $contentObject, $force_exit )
        {
            // Voeg de categorie toe aan de class als deze er nog niet in staat.
            // @todo disabled this binding because some category id's are mapped
            // to different ones, and we did not store the new name.
            //$this->updateClassCategories( $contentObject );
            return true;
        }

	function get_ezxml_handler()
	{
		include_once( 'extension/data_import/classes/inputparsers/connexysxmlinputparser.php' );
		return new ConnexysXMLInputParser( null );
	}
	
	/*
	 * Bouwt een array van vacature id's uit de XML, nodig om ontbrekende vacatures uit eZ te verwijderen
	 */
	function buildSourceIdArray()
	{
		$vacatures = $this->data->getElementsByTagName('vacature');
		foreach( $vacatures as $vacature )
		{
			$id = $vacature->getAttribute('id');
			$this->sourceIdArray[] = $id;
		}
	}

	// Controleert de bestaande rij op geldigheid van publicatie en publicatie data
	function checkVacancyValidity()
	{
		$publicaties = $this->current_row->getElementsByTagName('publicatie');
		foreach( $publicaties as $publicatie )
		{
			$id = $publicatie->getAttribute('id');
			if ($id == $this->publicationID) //de publicatie op mmc.nl, zie ini
			{
				if( $this->publicationIsCurrent( $publicatie ) )
				{
					return true;
				}
			}
		}
		return false;
	}
	
	function publicationIsCurrent($publicatie)
	{	
		$datum_van = $publicatie->getElementsByTagName('datum_van');
		$datum_van = $datum_van->item(0)->nodeValue;
		$datum_van = $this->convertDayToTimestamp($datum_van);
		
		$datum_tot = $publicatie->getElementsByTagName('datum_tot');
		$datum_tot = $datum_tot->item(0)->nodeValue;
		$datum_tot = $this->convertDayToTimestamp($datum_tot);
		
		// Also publish if datum_tot is empty
		if( $datum_van <= $this->today && ( $datum_tot > $this->today || $datum_tot == '' || $datum_tot == null || !$datum_tot ) )
		{
			$this->datum_van = $datum_van;
			$this->datum_tot = $datum_tot;
			
			$sollicitatie_link = $publicatie->getElementsByTagName('sollicitatie_link');
			$this->sollicitatie_link = $sollicitatie_link->item(0)->nodeValue;
			$mail_a_friend_link = $publicatie->getElementsByTagName('mail_a_friend_link');
			$this->mail_a_friend_link = $mail_a_friend_link->item(0)->nodeValue;

			return true;
		}
		return false;
	}
	
	function convertDayToTimestamp($string)
	{
		$return_unix_ts = gmmktime(); // Publication date defaults to today
		$nl_formatted_date = $string; //dd-mm-YY in xml
		$parts = explode('-', $nl_formatted_date );
		if( count( $parts ) == 3)
		{
			$return_unix_ts = mktime( 0, 0, 0, $parts[1], $parts[0] , $parts[2] );
		}
		return $return_unix_ts;

	}
	
}

?>