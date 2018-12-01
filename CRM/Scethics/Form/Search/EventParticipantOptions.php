<?php

/**
 * A custom contact search for event participants and all their choices
 */
class CRM_Scethics_Form_Search_EventParticipantOptions extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {
	protected $_eventID = NULL;
	protected $_tableName = NULL;
	protected $_customFields = NULL;
	
	// all custom fields pertinent to event Participants
	protected $_customFieldSQL =
	"SELECT g.name as gname, title, extends_entity_column_id, extends_entity_column_value, table_name, f.id, custom_group_id, f.name as fname, label, column_name
	FROM civicrm_custom_field f
	JOIN civicrm_custom_group g on g.id = f.custom_group_id
	WHERE extends = 'Participant' "; // AND f.is_active = 1 and g.is_active = 1";	//die('active');

 function __construct(&$formValues) {
    parent::__construct($formValues);
    
    $this->_eventID = CRM_Utils_Array::value('event_id', $this->_formValues);
    
    $this->setColumns(); // Find all fields in database that could be attached to an event participant/member.
    
    // After the user has selected an event...
    if ($this->_eventID) {
    	$this->createTable(); // i.e., CREATE TABLE statement...
    	$this->fillTable();      // corresponding SELECT INTO statement...
    }
  }
  
  function setColumns() {
  	// These should be common to ALL member meetings, so eventID doesn't matter
  	// Column_header => field_name
  	$this->_columns = array(
  			ts('Contact Id') => 'contact_id',						// civicrm_participant
  			ts('Participant Id') => 'participant_id',		// civicrm_participant
  			ts('Name') => 'sort_name',									// civicrm_contact
  			ts('Email') => 'email',											// civicrm_contact
  			ts('Dues Paid Through') => 'end_date', 			// civicrm_membership
  			ts('Registration Date') => 'register_date',	// civicrm_participant
  			ts('Tags') => 'tags',												// civicrm_option_value
  			ts('Notes') => 'note'												// civicrm_option_value
  	);
  
  	if (!$this->_eventID) return;
  
  	// Price Sets
  	// for the selected event, find the price set, and all the columns associated with it.
  	// create a column for each field and option group within it
  	$dao = $this->priceSetDAO($this->_formValues['event_id']); // Why not $this->_eventID?
  
  	if ($dao->fetch() && !$dao->price_set_id) CRM_Core_Error::fatal(ts('There are no events with Price Sets'));
  
  	// get all the price set fields and all the option values associated with it
  	$priceSet = CRM_Price_BAO_PriceSet::getSetDetail($dao->price_set_id); //var_dump($priceSet);
  	if (is_array($priceSet[$dao->price_set_id])) {
  		foreach ($priceSet[$dao->price_set_id]['fields'] as $key => $value) {
  			if (is_array($value['options'])) {
  				foreach ($value['options'] as $oKey => $oValue) {
  					$columnHeader = CRM_Utils_Array::value('label', $value);
  					if (CRM_Utils_Array::value('html_type', $value) != 'Text') $columnHeader .= ' - ' . $oValue['label'];
  					//var_dump("price_field_{$oValue['id']}");
  					$this->_columns[$columnHeader] = "price_field_{$oValue['id']}";
  				}
  			}
  		}
  	}
  	// print "<br/>get all the price set fields and all the option values associated with it"; var_dump($this->_columns);
  
  	// get all the custom fields associated with event Participants
  
  	$dao = CRM_Core_DAO::executeQuery($this->_customFieldSQL); //var_dump($this->_customFields);
  	while ($dao->fetch()) {
  		$columnHeader = $dao->label;
  		$this->_columns[$columnHeader] = $dao->column_name; //print "<br/>{$columnHeader}<br/>"; var_dump($this->_columns[$columnHeader]);
  	}
  }
  
  function createTable() {
  	$this->_tableName = "Event_Participant_Report_{$this->_eventID}";
  	$textField = "varchar(256) default '',\n";  	
  	
  	$sql = "DROP TABLE IF EXISTS {$this->_tableName};";
  	CRM_Core_DAO::executeQuery($sql);

  	$sql  = "CREATE TABLE IF NOT EXISTS  " . $this->_tableName;
  	$sql .= "( id int unsigned NOT NULL AUTO_INCREMENT, contact_id int unsigned NOT NULL, participant_id int unsigned NOT NULL, end_date date , register_date date, ";

  	// var_dump($this->_columns);
  	foreach ($this->_columns as $dontCare => $fieldName) {
  		if (in_array($fieldName, array(
  				'contact_id',
  				'participant_id',
  				'sort_name',
  				'email',
  				'end_date',
  				'register_date',
  				'note'
  		))) {
  			continue;
  		}
  		//var_dump($fieldName);
  		$sqlField = "{$fieldName} varchar(256) default '',\n";
  		$sql .= $sqlField;
  	}

  	$sqlKeys .= " PRIMARY KEY ( id ),  	UNIQUE INDEX unique_participant_id ( participant_id )  	) ENGINE=INNODB";

  	$sql .= $sqlKeys;
  	CRM_Core_DAO::executeQuery($sql);
  }
  
  function fillTable() {
  	$sqlReplace = "
  	( contact_id, participant_id, end_date , register_date)
  	SELECT c.id, p.id, max(m.end_date), p.register_date
  	FROM              civicrm_contact     c
  	LEFT OUTER JOIN civicrm_participant p ON p.contact_id = c.id
  	LEFT OUTER JOIN civicrm_membership  m ON m.contact_id = c.id
  	WHERE  p.is_test    = 0
  	AND  p.event_id = {$this->_eventID}
  	-- AND  p.status_id NOT IN (4,11) -- ,12)
  	AND  ( c.is_deleted = 0 OR c.is_deleted IS NULL )
  	GROUP BY p.id
  	";
  	CRM_Core_DAO::executeQuery("REPLACE INTO {$this->_tableName} " . $sqlReplace );

  	$sql = "SELECT c.id as contact_id, p.id as participant_id,l.price_field_value_id as price_field_value_id, l.qty
  	FROM civicrm_contact c LEFT OUTER JOIN civicrm_participant p ON p.contact_id = c.id
  	JOIN civicrm_line_item l ON p.id = l.entity_id
  	AND  p.event_id = {$this->_eventID} AND p.id = l.entity_id AND l.entity_table ='civicrm_participant'
  	JOIN civicrm_price_field f ON f.id = l.price_field_id	WHERE f.is_active = 1
  	ORDER BY c.id, l.price_field_value_id"; //var_dump($sql);

  	$dao = CRM_Core_DAO::executeQuery($sql);

  	// first store all the information by option value id
  	$rows = array();
  	while ($dao->fetch()) {
  		$contactID = $dao->contact_id;
  		$participantID = $dao->participant_id;
  		if (!isset($rows[$participantID])) {
  			$rows[$participantID] = array();
  		}
  		if (isset($dao->price_field_value_id))
  			$rows[$participantID][] = "price_field_{$dao->price_field_value_id} = {$dao->qty}";
  	}

  	foreach (array_keys($rows) as $participantID) {
  		$values = implode(',', $rows[$participantID]);
  		$sql = "UPDATE {$this->_tableName} SET $values WHERE participant_id = $participantID"; //var_dump($sql);
  		CRM_Core_DAO::executeQuery($sql);
  	}

  	$daoCustomField = CRM_Core_DAO::executeQuery($this->_customFieldSQL); //var_dump($this->_customFields);
  	while ($daoCustomField->fetch()) {
  		$table_name = $daoCustomField->table_name;  //var_dump($table_name);
  		$column_name = $daoCustomField->column_name;  //var_dump($column_name);
  		$sql = "UPDATE {$this->_tableName} JOIN {$table_name} ON {$table_name}.entity_id = {$this->_tableName}.participant_id
  		SET {$this->_tableName}.{$column_name} = {$table_name}.{$column_name}"; //var_dump($sql);
  		CRM_Core_DAO::executeQuery($sql);
  	}
  }

  /**
   * Prepare a set of search fields
   *
   * @param CRM_Core_Form $form modifiable
   * @return voidbuildForm
   */
  function buildForm(&$form) {
    /**
     * You can define a custom title for the search form
     */
    $this->setTitle('Event Participant Search');
    
    $dao = $this->priceSetDAO();

    $event = array();
    while ($dao->fetch()) {
      $event[$dao->id] = $dao->title;
    }

    if (empty($event)) {
      CRM_Core_Error::fatal(ts('There are no events with Price Sets'));
    }

    $form->add('select',
      'event_id',
      ts('Event'),
      $event,
      TRUE
    );

    /**
     * if you are using the standard template, this array tells the template what elements
     * are part of the search criteria
     */
    $form->assign('elements', array('event_id'));
  }

  function priceSetDAO($eventID = NULL) {
  
  	// get all the events that have a price set associated with it
  	$sql = "  	SELECT e.id    as id,  	e.title as title,  	p.price_set_id as price_set_id
  	FROM   civicrm_event      e,  	civicrm_price_set_entity  p  
  	WHERE  p.entity_table = 'civicrm_event'
  	AND    p.entity_id    = e.id
  	ORDER BY e.title
  	";
  
  	$params = array();
  	if ($eventID) {
  		$params[1] = array($eventID, 'Integer');
  		$sql .= " AND e.id = $eventID";
  	}
  
  	$dao = CRM_Core_DAO::executeQuery($sql,  			$params  	);
  	return $dao;
  }
  
  /**
   * Get a list of summary data points
   *
   * @return mixed; NULL or array with keys:
   *  - summary: string
   *  - total: numeric
   */
  function summary() {
    return NULL;
    // return array(
    //   'summary' => 'This is a summary',
    //   'total' => 50.0,
    // );
  }

  /**
   * Get a list of displayable columns
   *
   * @return array, keys are printable column headers and values are SQL column names
   */
  function &columns() {
    // return by reference
    $columns = array(
      ts('Contact Id') => 'contact_id',
//       ts('Contact Type') => 'contact_type',
//       ts('Name') => 'sort_name',
//       ts('State') => 'state_province',
    );
    
    $columns = array(
    		ts('Contact Id') => 'contact_id',
    		ts('Participant Id') => 'participant_id',
    		ts('Name') => 'sort_name',
    		ts('Email') => 'email',
    		ts('Dues Paid Through') => 'end_date',
    		ts('Registration Date') => 'register_date',
    		ts('Tags') => 'tags',
    		ts('Notes') => 'note'
    );
    
    if (!$this->_eventID) {
    	return;
    }
    
    // for the selected event, find the price set, and all the columns associated with it.
    // create a column for each field and option group within it
    $dao = $this->priceSetDAO($this->_formValues['event_id']);
    
    if ($dao->fetch() &&
    		!$dao->price_set_id
    ) {
    	CRM_Core_Error::fatal(ts('There are no events with Price Sets'));
    }
    
    // get all the fields and all the option values associated with it
    $priceSet = CRM_Price_BAO_PriceSet::getSetDetail($dao->price_set_id); //var_dump($priceSet);
    if (is_array($priceSet[$dao->price_set_id])) {
    	foreach ($priceSet[$dao->price_set_id]['fields'] as $key => $value) {
    		if (is_array($value['options'])) {
    			foreach ($value['options'] as $oKey => $oValue) {
    				$columnHeader = CRM_Utils_Array::value('label', $value);
    				if (CRM_Utils_Array::value('html_type', $value) != 'Text') {
    					$columnHeader .= ' - ' . $oValue['label'];
    				}
    				//var_dump("price_field_{$oValue['id']}");
    				$columns[$columnHeader] = "price_field_{$oValue['id']}";
    			}
    		}
    	}
    }
    
    // get all the custom fields associated with event Participants
    
    $dao = CRM_Core_DAO::executeQuery($this->_customFieldSQL); //var_dump($this->_customFields);
    while ($dao->fetch()) {
    	$columnHeader = $dao->label;
    	$columns[$columnHeader] = $dao->column_name; //print "<p>{$columnHeader}</p>"; var_dump($columns[$columnHeader]);
    }
    
    return $columns;
  }

  /**
   * Construct a full SQL query which returns one page worth of results
   *
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param bool $includeContactIDs
   * @param bool $justIDs
   * @return string, sql
   */
  function all($offset = 0, $rowcount = 0, $sort = NULL, $includeContactIDs = FALSE, $justIDs = FALSE) {
    if ($justIDs) {
      $selectClause = "contact_a.id as contact_id";
    }
    else {
      $selectClause = "contact_a.id as contact_id, contact_e.email as email, contact_a.sort_name as sort_name";
      $selectClause = "contact_a.id as contact_id, contact_e.email as email, contact_a.sort_name as sort_name, GROUP_CONCAT(contact_t.name) as tags, contact_n.note as note";

      foreach ($this->_columns as $dontCare => $fieldName) {
        if (in_array($fieldName, array(
              'contact_id',
              'sort_name',
              'email',
              'tags',
              'note',
            ))) {
          continue;
        }
        $selectClause .= ",\ntempTable.{$fieldName} as {$fieldName}";
      }
    }
//var_dump($selectClause);
//die("294");

    $groupBy = " GROUP BY contact_a.id ";
    $sqlQuery = $this->sql($selectClause, $offset, $rowcount, $sort,$includeContactIDs, $groupBy);
//var_dump($sqlQuery); die(341);
    return $sqlQuery;
  }


  /**
   * Construct a SQL FROM clause
   *
   * @return string, sql fragment with FROM and JOIN clauses
   */
  function from() {
    return "FROM  {$this->_tableName} tempTable 
      LEFT OUTER JOIN civicrm_contact contact_a ON tempTable.contact_id = contact_a.id 
      LEFT OUTER JOIN civicrm_email contact_e ON contact_a.id = contact_e.contact_id AND is_primary = 1 
      LEFT OUTER JOIN civicrm_entity_tag ON civicrm_entity_tag.entity_id = tempTable.contact_id
      LEFT OUTER JOIN civicrm_note contact_n ON contact_n.entity_id = tempTable.participant_id AND contact_n.entity_table LIKE 'civicrm_participant'
      LEFT OUTER JOIN civicrm_tag contact_t ON civicrm_entity_tag.tag_id = contact_t.id AND civicrm_entity_tag.entity_table LIKE 'civicrm_contact'"
    ;
    //return "FROM {$this->_tableName} tempTable LEFT OUTER JOIN civicrm_contact contact_a ON ( tempTable.contact_id = contact_a.id )
    //  LEFT OUTER JOIN civicrm_email contact_e ON contact_a.id = contact_e.contact_id AND is_primary = 1 ";
  }

  /**
   * Construct a SQL WHERE clause
   *
   * @param bool $includeContactIDs
   * @return string, sql fragment with conditional expressions
   */
  function where($includeContactIDs = FALSE) {
    return ' ( 1 ) ';
  }

  /**
   * Determine the Smarty template for the search screen
   *
   * @return string, template path (findable through Smarty template path)
   */
  function templateFile() {
    return 'CRM/Contact/Form/Search/Custom.tpl';
  }

  /**
   * Modify the content of each row
   *
   * @param array $row modifiable SQL result row
   * @return void
   */
  function alterRow(&$row) {
    $row['sort_name'] .= ' ( altered )';
  }
  
  function setDefaultValues() {
  	return array();
  }
}
