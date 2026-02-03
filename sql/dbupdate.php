<#1>
<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('xmdl_config')) {
    $fields = [
        'name' => ['type' => 'text', 'length' => 250, 'notnull' => true],
        'value' => ['type' => 'text', 'length' => 4000, 'notnull' => false]
    ];
    $db->createTable('xmdl_config', $fields);
    $db->addPrimaryKey('xmdl_config', ['name']);
}

if (!$db->tableExists('rep_robj_xmdl_data')) {
    $fields = [
        'id' => ['type' => 'integer', 'length' => 8, 'notnull' => true],
        'is_online' => ['type' => 'integer', 'length' => 4, 'notnull' => false, 'default' => 0]
    ];
    $db->createTable('rep_robj_xmdl_data', $fields);
    $db->addPrimaryKey('rep_robj_xmdl_data', ['id']);
}

if (!$db->tableExists('rep_robj_xmdl_pages')) {
    $fields = [
        'id' => ['type' => 'integer', 'length' => 8, 'notnull' => true],
        'module_id' => ['type' => 'integer', 'length' => 8, 'notnull' => true],
        'title' => ['type' => 'text', 'length' => 255, 'notnull' => true],
        'content' => ['type' => 'clob', 'notnull' => true],
        'page_number' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
        'sort_order' => ['type' => 'integer', 'length' => 4, 'notnull' => false, 'default' => 0],
        'created_date' => ['type' => 'timestamp', 'notnull' => false]
    ];
    $db->createTable('rep_robj_xmdl_pages', $fields);
    $db->addPrimaryKey('rep_robj_xmdl_pages', ['id']);
    $db->addIndex('rep_robj_xmdl_pages', ['module_id'], 'i1');
    $db->createSequence('rep_robj_xmdl_pages');
}

if (!$db->tableExists('rep_robj_xmdl_progress')) {
    $fields = [
        'user_id' => ['type' => 'integer', 'length' => 8, 'notnull' => true],
        'module_id' => ['type' => 'integer', 'length' => 8, 'notnull' => true],
        'last_page_viewed' => ['type' => 'integer', 'length' => 4, 'notnull' => false],
        'completed_pages' => ['type' => 'text', 'length' => 4000, 'notnull' => false],
        'last_access' => ['type' => 'timestamp', 'notnull' => false]
    ];
    $db->createTable('rep_robj_xmdl_progress', $fields);
    $db->addPrimaryKey('rep_robj_xmdl_progress', ['user_id', 'module_id']);
}
?>
