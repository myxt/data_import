<?php

class ConnexysVacanciesHandler extends XmlHandler
{

    var $current_loc_info = array();

    var $remoteID = "";

    var $current_counter = 0;
    var $sourceIdArray = array();

    var $classCategories;

    var $publishVacancy = false;

    protected $dataINI;
    protected $handlerTitle = 'Connexys Vacancies';
    protected $logfile = 'connexys_vacancies_import.log';

    public $parentNodeID;
    public $classIdentifier;
    public $publicationID;

    public $idPrepend = 'connexys_vacancy_';

    /*
     * Variables saving some vacancy parameters.
     */
    private $registrationLink;
    private $dateFrom;
    private $dateClosed;

    /**
     * The enhanced selection category hash the current vacancy
     * @var array
     */
    private $categories = array();

    /**
     * Special fields.
     */
    private $specialFieldCounter = 0;
    private $specialFields = array( 'DisplayJobTitle',
                                    'CompanyInformation',
                                    'FunctionDescription',
                                    'JobRequirements',
                                    'Compensation',
                                    'ContactInfo',
                                    'Subtitle',
                                    'RegistrationLink' );

    function __construct()
    {
        $this->dataINI = eZINI::instance('data_import.ini');
        $this->parentNodeID = $this->dataINI->variable('xmlhandler_connexys_vacancies', 'ParentNodeID');
        $this->classIdentifier = $this->dataINI->variable('xmlhandler_connexys_vacancies', 'ClassIdentifier');
        $this->publicationID = $this->dataINI->variable('xmlhandler_connexys_vacancies', 'XmlPublicationID');
        $this->classCategories = $this->getClassCategories();
    }

    /**
     * !reimp
     * Fetches the XML and runs the parsing function.
     * @return bool
     */
    function readData()
    {
        // TODO SvdA version check, skip for now.
        return $this->parse_xml_document($this->dataINI->variable('xmlhandler_connexys_vacancies', 'XmlDocument'), 'Vacancies');
    }

    /**
     * Returns the parser to use
     * @return ConnexysXMLInputParser the parser class instance.
     */
    function get_ezxml_handler()
    {
        return new ConnexysXMLInputParser(null);
    }

    /**
     * Returns the locale to create objects in.
     * @return string
     */
    public function getTargetLanguage()
    {
        $ini = eZINI::instance();
        return $ini->variable( 'RegionalSettings', 'ContentObjectLocale' );
    }

    /**
     * Get the next vacancy in the XML and return the DOM.
     * @return DOMElement the next vacancy.
     */
    function getNextRow()
    {
        // Reset stuff.
        $this->first_field = true;
        $this->node_priority = false;
        $this->current_counter = 0;
        $this->specialFieldCounter = 0;
        $this->categories = array();

        // If this is the first node, it contains version info.
        // @TODO SvdA version check.
        if ($this->first_row) {
            $this->first_row = false;
            $this->current_row = $this->data->firstChild;
        } else {
            $this->current_row = $this->current_row->nextSibling;
        }

        if ($this->current_row->tagName != 'Vacancy') {
            $this->current_row = $this->getNextValidNode($this->current_row);
        }
        //var_dump($this->current_row);
        return $this->current_row;
    }

    /**
     * Get the next attribute to map in the current row/vacancy.
     * @return DOMElement the next field
     */
    function getNextField()
    {
        if ($this->first_field) {
            $this->first_field = false;
            $this->current_field = $this->current_row->firstChild;
        } else {
            $this->current_field = $this->current_field->nextSibling;
        }

        if (is_object($this->current_field) && $this->current_field->nodeType != 1) //ignore xml #text nodes
        {
            $this->current_field = $this->getNextValidNode($this->current_field);
        }

        return $this->current_field;
    }

    /**
     * Handles some special fields that are not directly beneath the vacancy tag.
     * @return mixed
     */
    function getSpecialField()
    {
        if( $this->specialFieldCounter < count($this->specialFields ) )
        {
            $nodeList = $this->current_row->getElementsByTagName( $this->specialFields[$this->specialFieldCounter] );
            $this->current_field = $nodeList->item(0);
            $this->specialFieldCounter++;
            return $this->current_field;
        }
        else return false;
    }

    /**
     * @return string
     */
    function geteZAttributeIdentifierFromField()
    {
        switch ($this->current_field->tagName) {
            case 'FunctionGroup1':
            case 'FunctionGroup2':
            case 'FunctionGroup3':
                return 'category';

            case 'Recruiter':
                return 'recruiter_email';

            case 'DateFrom':
                return 'publish_date';

            case 'DateClosed':
                return 'unpublish_date';

            default:
                return strtolower($this->current_field->tagName);
        }
    }

    function getValueFromField()
    {
        switch ($this->current_field->tagName) {

            case 'OrganizationUnit':
            {
                $type = $this->current_field->getAttribute('type');
                if ($type == 3)
                    return $this->current_field->firstChild->nodeValue;

                $departments = $this->current_field->getElementsByTagName('OrganizationUnit');
                foreach ($departments as $department) {
                    $type = $department->getAttribute('type');
                    if ($type == 3)
                        return $department->nodeValue;
                }
            }

            case 'FunctionGroup1':
            case 'FunctionGroup2':
            case 'FunctionGroup3':
            {
                /* Kijk of deze categorie al bestaat in de enhanced
                   selection en voeg toe aan content class. */
                $id = $this->current_field->getAttribute('id');
                $value = $this->current_field->firstChild->nodeValue;
                $this->addCategory($id, $value);
                return implode(',', array_keys($this->categories));
                break;
            }

            case 'CompanyInformation':
            case 'FunctionDescription':
            case 'JobRequirements':
            case 'Compensation':
            case 'ContactInfo':
            case 'Subtitle':
            {
                $xml_text_parser = new XmlTextParser();
                $xmltext = $xml_text_parser->Html2XmlText( $this->current_field->nodeValue );

                if($xmltext !== false)
                {
                    return $xmltext;
                }
                else
                {
                    // TODO: log this.
                    return "";
                }
            }

            case 'DateFrom':
            {
                return $this->dateFrom;
                break;
            }
            case 'DateClosed':
            {
                return $this->dateClosed;
                break;
            }

            case 'Recruiter':
            {
                return $this->current_field->getElementsByTagName('WorkEmail')->item(0)->nodeValue;
            }

            case 'RegistrationLink':
            {
                return $this->registrationLink;
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
        $parentNodeId = $this->parentNodeID;

        $parentRemoteId = $this->current_row->getAttribute('parent_id');
        if ($parentRemoteId) {
            $object = eZContentObject::fetchByRemoteID($this->idPrepend . $parentRemoteId);
            if ($object) {
                $parentNodeId = $object->attribute('main_node_id');
            }
        }

        return $parentNodeId;
    }

    function getDataRowId()
    {
        return $this->idPrepend . $this->current_row->getAttribute('id');
    }

    function getTargetContentClass()
    {
        return $this->classIdentifier;
    }

    function post_save_handling($contentObject, $force_exit)
    {
        // Voeg de categorie toe aan de class als deze er nog niet in staat.
        // @todo disabled this binding because some category id's are mapped
        // to different ones, and we did not store the new name.
        $this->updateClassCategories($contentObject);
        return true;
    }

    /**
     * Returns the enhanced selection entries that are currently in the content class.
     * @return array hash with id's and descriptions.
     */
    function getClassCategories()
    {
        $categories = array();
        $class = eZContentClass::fetchByIdentifier($this->classIdentifier);
        $categoriesAttribute = $class->fetchAttributeByIdentifier('category');
        $xmlString = new SimpleXMLElement($categoriesAttribute->attribute('data_text5'));
        foreach ($xmlString->options->option as $option) {
            $categories[(string)$option['identifier']] = (string)$option['name'];

        }

        return $categories;
    }

    /**
     * Checks if all categories of the current vacancy are in the enhanced selection attribute of
     * the content class as well. Adds them if needed.
     * @return bool
     */
    function updateClassCategories()
    {
        $classCategories = $this->getClassCategories();
        foreach ($this->categories as $key => $category) {
            if (!isset($classCategories[$key])) {
                $this->addClassCategory($key, $category);
            }
        }
    }

    /**
     * Adds a new category to the enhanced selection in the content class.
     * @param [type] $identifier the id of the new category
     * @param [type] $name       the description of the new category
     */
    function addClassCategory($identifier, $name)
    {
        $name = trim($name);
        $identifier = trim($identifier);

        if ($name !== '' && $identifier !== '') {
            $class = eZContentClass::fetchByIdentifier($this->classIdentifier);
            $categoriesAttribute = $class->fetchAttributeByIdentifier('category');
            $xmlString = new SimpleXMLElement($categoriesAttribute->attribute('data_text5'));
            $count = count($xmlString->options->option);

            $newOption = $xmlString->options->addChild('option');
            $newOption->addAttribute('id', $count++);
            $newOption->addAttribute('identifier', $identifier);
            $newOption->addAttribute('name', $name);
            $newOption->addAttribute('priority', 1);
            $categoriesAttribute->setAttribute('data_text5', $xmlString->asXML());
            $categoriesAttribute->store();

            eZDebug::writeNotice('Added new vacancy category ' . $name . ' to content class.');
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
    function addCategory($identifier, $name)
    {
        $groupMap = $this->dataINI->variable('xmlhandler_connexys_vacancies', 'FunctionGroupToCategoryMap');

        $name = trim($name);
        $identifier = trim($identifier);
        if (isset($this->categories[$identifier]) || $name == '' || $identifier == ''
        )
            return;

        $this->categories[$identifier] = $name;
        /*
        if (isset($groupMap[$identifier]))
            $categories = explode(';', $groupMap[$identifier]);
        else
            $categories = array($this->dataINI->variable('xmlhandler_connexys_vacancies', 'DefaultCategory'));

        foreach ($categories as $category)
            $this->categories[$category] = $category;
        */
    }

    /**
     * Bouwt een array van vacature id's uit de XML, nodig om ontbrekende vacatures uit eZ te verwijderen
     */
    function buildSourceIdArray()
    {
        $vacatures = $this->data->getElementsByTagName('Vacancy');
        foreach ($vacatures as $vacature) {
            $id = $vacature->getAttribute('id');
            $this->sourceIdArray[] = $id;
        }
    }

    /**
     * Controleert de bestaande rij op geldigheid van publicatie en publicatie data.
     *
     * @return bool
     */
    function checkVacancyValidity()
    {
        $publications = $this->current_row->getElementsByTagName('Publication');
        foreach ($publications as $publication) {
            if ($publication->getAttribute('pub_id') !== $this->publicationID)
                continue;

            if ($this->publicationIsCurrent($publication))
                return true;
        }
        return false;
    }

    /**
     * Checks if a publication is current: today is between the start and end dates of the publication.
     *
     * @param $publicatie
     * @return bool
     */
    function publicationIsCurrent($publicatie)
    {
        $today = $this->convertDayToTimestamp(gmdate('d-m-Y'));

        $datum_van = $publicatie->getElementsByTagName('DateFrom');
        $datum_van = $datum_van->item(0)->nodeValue;
        $datum_van = $this->convertDayToTimestamp($datum_van);

        $datum_tot = $publicatie->getElementsByTagName('DateUntil');
        $datum_tot = $datum_tot->item(0)->nodeValue;
        $datum_tot = $this->convertDayToTimestamp($datum_tot);

        // Also publish if datum_tot is empty
        if ($datum_van <= $today && ($datum_tot > $today || $datum_tot == '' || $datum_tot == null || !$datum_tot)) {
            $this->dateFrom = $datum_van;
            $this->dateClosed = $datum_tot;

            $sollicitatie_link = $publicatie->getElementsByTagName('RegistrationLink');
            $this->registrationLink = $sollicitatie_link->item(0)->nodeValue;

            return true;
        }
        return false;
    }

    /**
     * Converts a day-monty-year string to a unix timestap.
     *
     * @param $string
     * @return timestamp
     */
    function convertDayToTimestamp($string)
    {
        $return_unix_ts = gmmktime(); // Publication date defaults to today
        $nl_formatted_date = $string; //dd-mm-YY in xml
        $parts = explode('-', $nl_formatted_date);
        if (count($parts) == 3) {
            $return_unix_ts = mktime(0, 0, 0, $parts[1], $parts[0], $parts[2]);
        }
        return $return_unix_ts;

    }

}

?>