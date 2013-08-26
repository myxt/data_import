<?php

class ConnexysVacaturesOperator extends ImportOperator
{

	function VacaturesOperator( $handler )
	{
		$this->source_handler = $handler;
		$this->source_handler->logger = new eZLog();
		$this->cli = eZCLI::instance();
		$this->cli->setUseStyles( true );
	}

	function run()
	{
		$this->source_handler->readData();
		$this->source_handler->buildSourceIdArray();
		
		/*
		 * Het script loopt eerst door de bestaande objecten in eZ Publish,
		 * want we weten niet zeker of alle objecten nog in de XML staan.
		 * Objecten waarvan de remote_id niet in de XML staat worden verwijderd (pass 1).
		 */
		$this->cleanupeZVacancies();
		
		/*
		 * Vervolgens loopt het script door de vacatures in de XML.
		 * Nieuwe vacatures worden als object aangemaakt;
		 * Bestaande vacatures worden geupdate;
		 * Verlopen vacatures worden verwijderd (pass 2).
		 */
		$this->handleXMLVacancies();
		
	}

	/*
	 * Cleans up leftover eZ vacancies that are not present in the XML
	 */
	function cleanupeZVacancies()
	{
		$this->cli->output( $this->cli->stylize( 'cyan', "\nCleaning up old vacancies in eZ Publish.\n" ), false );
		
		$parentNodeID = $this->source_handler->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'parentNodeID' );
		$importClassIdentifier = $this->source_handler->dataINI->variable( 'xmlhandler_connexysVacatures_v2', 'importClassIdentifier' );
		$parentNode = eZContentObjectTreeNode::fetch( $parentNodeID );
		$nodes = $parentNode->attribute( 'children' );
		foreach( $nodes as $node )
		{
			if( $node->ClassIdentifier == $importClassIdentifier )
			{
				$object = eZContentObject::fetchByNodeID( $node->attribute('node_id') );
				$remoteID = $object->attribute('remote_id');
				
				$this->cli->output( 'Checking eZ object ('.$this->cli->stylize( 'emphasize', $remoteID ).') of type ('.$this->cli->stylize( 'emphasize', $this->source_handler->getTargetContentClass() ).')... ' , false );
				
				$sourceID = $this->extractIdFromRemoteId( $remoteID );
				if(	!in_array( $sourceID , $this->source_handler->sourceIdArray ) )
				{
					$this->remove_eZ_object( $object );
					$this->cli->output( $this->cli->stylize( 'green', "successfully removed.\n" ), false );
				}
				else
					$this->cli->output( $this->cli->stylize( 'gray', "skipped.\n" ), false );
			}
		}

	}
	
	function handleXMLVacancies()
	{
		$this->cli->output( $this->cli->stylize( 'cyan', "\nHandling XML vacancies.\n" ), false );
		
		$force_exit = false;
		while( $row = $this->source_handler->getNextRow() && !$force_exit )
		{
			$this->current_eZ_object = null;
			$this->current_eZ_version = null;
			
		    $remoteID           = $this->source_handler->getDataRowId();
			$targetContentClass = $this->source_handler->getTargetContentClass();
			$targetLanguage     = $this->source_handler->getTargetLanguage();

			$this->cli->output( 'Checking remote object ('.$this->cli->stylize( 'emphasize', $remoteID ).') as eZ object ('.$this->cli->stylize( 'emphasize', $targetContentClass ).')... ' , false );

			$this->current_eZ_object = eZContentObject::fetchByRemoteID( $remoteID );
			
			$update_method = '';

			// Kijk eerst of de vacature geldig is, dus of er een publicatie voor internet is
			// Zo ja, kijk dan of de data actueel zijn
			// Als de vacature niet geldig is dan niet verder gaan met publiceren en bestaande vacature verwijderen
			$isValidVacancy = $this->source_handler->checkVacancyValidity();
			
			// Deze vacature heeft een actuele publicatie
			if($isValidVacancy)
			{
				if( !$this->current_eZ_object )
				{
					$update_method = 'created';
					$this->create_eZ_node( $remoteID, $row, $targetContentClass, $targetLanguage );
				}
				else
				{
					$update_method = 'updated';
					$this->update_eZ_node( $remoteID, $row, $targetContentClass, $targetLanguage );
				}
			
			}
			else
			{
				if( $this->current_eZ_object ) // Bestaande vacature verwijderen
				{
					$this->remove_eZ_object ( $this->current_eZ_object );
					$this->cli->output( $this->cli->stylize( 'green', 'succesfully removed.'."\n" ), false );
				}
				else // Niets doen
					$this->cli->output( $this->cli->stylize( 'gray', 'skipped.'."\n" ), false );
					
				// Door naar de volgende vacature
				continue;
			}

			if( $this->current_eZ_object && $this->current_eZ_version )
			{
				$this->save_eZ_node();
				
				$post_save_success = $this->source_handler->post_save_handling( $this->current_eZ_object, $force_exit );

				if( $post_save_success )
				{
					$this->publish_eZ_node();
					
					$post_publish_success = $this->source_handler->post_publish_handling( $this->current_eZ_object, $force_exit );
					
					if( $post_publish_success )
					{
						$this->setNodesPriority();
	
						$this->cli->output( '..'.$this->cli->stylize( 'green', 'successfully '.$update_method.".\n" ), false );
					}
					else
					{
						$this->cli->output( '..'.$this->cli->stylize( 'red', 'post handling after publish not successful.'."\n" ), false );
					}
				}
				else
				{
					$this->cli->output( '..'.$this->cli->stylize( 'red', 'post handling after save not successful.'."\n" ), false );
				}
				
				# Clear content object from $GLOBALS - to prevent OOM (not mana)
				ezContentObject::clearCache( $this->current_eZ_object->attribute('id') );
			}
			else
			{
				$this->cli->output( '..'.$this->cli->stylize( 'gray', 'skipped.'."\n" ), false );
			}
		}
	}
	
	function update_eZ_node( $remoteID, $row, $targetContentClass, $targetLanguage = null )
	{
		$this->do_publish = false;
		$this->current_eZ_version = $this->current_eZ_object;
		return true;
	}
	
	function remove_eZ_object( $eZ_object )
	{
		$object_id = $eZ_object->attribute('id');
		$assigned_nodes = $eZ_object->attribute('assigned_nodes');
		
		$deleteIDArray = array();
		$moveToTrash = false;
		
		foreach($assigned_nodes as $assigned_node)
		{
			$assigned_node->remove();
		}
		
		$eZ_object->remove();
		$eZ_object->purge();
	}
	
	function extractIdFromRemoteId( $remoteID )
	{
		$remoteParts = explode( '_', $remoteID );
		return $remoteParts[2];
	}
	
}

?>