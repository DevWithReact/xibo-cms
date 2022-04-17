<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Widget;

use Carbon\Carbon;
use Respect\Validation\Validator as v;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Entity\DataSetColumn;
use Xibo\Support\Exception\InvalidArgumentException;
use Xibo\Support\Exception\NotFoundException;
use Xibo\Storage\PdoStorageService;
use Xibo\Support\Exception\GeneralException;
/**
 * Class DataSetView
 * @package Xibo\Widget
 */
class DataSetView extends ModuleWidget
{
    /**
     * @inheritDoc
     */
    public function installFiles()
    {
        // Extends parent's method
        parent::installFiles();

        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/modules/vendor/jquery-cycle-2.1.6.min.js')->save();
        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/modules/vendor/moment.js')->save();
        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/modules/xibo-dataset-render.js')->save();
        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/modules/xibo-image-render.js')->save();
    }

    /** @inheritdoc */
    public function layoutDesignerJavaScript()
    {
        return 'datasetview-designer-javascript';
    }

    /**
     * Get DataSet object, used by TWIG template.
     *
     * @return array
     * @throws NotFoundException
     */
    public function getDataSet()
    {
        if ($this->getOption('dataSetId') != 0) {
            return [$this->dataSetFactory->getById($this->getOption('dataSetId'))];
        } else {
            return null;
        }
    }

    /**
     * Get DataSet Columns
     * @return array
     */
    public function dataSetColumns()
    {
        if ($this->isCustomQuery())
            return $this->getCustomQueryColumns();
        return $this->dataSetColumnFactory->getByDataSetId($this->getOption('dataSetId'));
    }

    public function isCustomQuery() {
        $useQuery = $this->getOption('useQuery');
        return $useQuery == '1';
    }

    public function getCustomData () {
        $pdo = new PdoStorageService();
        $customHost = $this->getOption('customHost');
        $customDBUser = $this->getOption('customDBUser');
        $customDBPassword = $this->getOption('customDBPassword');
        $customDBName = $this->getOption('customDBName');
        $customSql = $this->getOption('customSql');
        $useQuery = $this->getOption('useQuery');
        try {
            $pdo->connect($customHost, $customDBUser, $customDBPassword, $customDBName);
            $result = $pdo->select($customSql,[]);
            return $result;
        } catch (\PDOException $e) {
            throw new GeneralException('[getCustomData] Custom Database connection Faied. ['.$customSql.'], '.$e->getMessage());
        }
    }

    public function getCustomQueryColumns() {
        $pdo = new PdoStorageService();
        $customHost = $this->getOption('customHost');
        $customDBUser = $this->getOption('customDBUser');
        $customDBPassword = $this->getOption('customDBPassword');
        $customDBName = $this->getOption('customDBName');
        $customSql = $this->getOption('customSql');
        $useQuery = $this->getOption('useQuery');
        try {
            $pdo->connect($customHost, $customDBUser, $customDBPassword, $customDBName);
            $randomName = 'temp'.random_int(0, 10000);
            if (preg_match('/limit\s+(\d+)/i', $customSql) == 1) {
                $pattern = '/limit\s+(\d+)/i';
                $replacement = 'limit 1';
                $customSql = preg_replace($pattern, $replacement, $customSql);
            } else {
                str_replace(';', '', $customSql);
                $customSql = $customSql . ' limit 1;';
            }
            $pdo->select('create view '.$randomName.' as '.$customSql,[]);
            $rows= $pdo->select('select COLUMN_NAME from information_schema.COLUMNS where TABLE_NAME=\''.$randomName.'\';',[]);
            $pdo->select('drop view '.$randomName, []);
            foreach($rows as $row) {
                if ($row['COLUMN_NAME'] == 'id')
                    continue;
                $columns[] = (object)array(
                    'dataSetColumnId'=> $row['COLUMN_NAME'],
                    'heading'=> $row['COLUMN_NAME']
                );;
            }
            return $columns;
        } catch (\PDOException $e) {
            throw new GeneralException('[getCustomQueryColumns] Custom Database connection Faied. ['.$customSql.'] ,'.$e->getMessage());
        }
        
    }
    /**
     * Get Data Set Columns
     * @return array[DataSetColumn]
     * @throws InvalidArgumentException
     */
    public function dataSetColumnsSelected()
    {
        if ($this->getOption('dataSetId') == 0 && $this->getOption('useQuery') != '1') {
            throw new InvalidArgumentException(__('DataSet not selected'));
        }

        if ($this->getOption('useQuery') == '1') {
            $columns = $this->getCustomQueryColumns();
        } else {
            $columns = $this->dataSetColumnFactory->getByDataSetId($this->getOption('dataSetId'));
        }

        $columnsSelected = [];
        $colIds = explode(',', $this->getOption('columns'));

        // Cycle elements of the ordered columns Ids array $colIds
        foreach ($colIds as $colId) {
            // Cycle data set columns $columns
            foreach ($columns as $column) {
                // See if the element on the odered list is the column
                if ($column->dataSetColumnId == $colId) {
                    $columnsSelected[] = $column;
                }
            }
        }

        return $columnsSelected;
    }

    /**
     * Get Data Set Columns
     * @return array[DataSetColumn]
     * @throws InvalidArgumentException
     */
    public function dataSetColumnsNotSelected()
    {
        if ($this->getOption('dataSetId') == 0 && $this->getOption('useQuery') != '1') {
            throw new InvalidArgumentException(__('DataSet not selected'));
        }

        if ($this->getOption('useQuery') == '1') {
            $columns = $this->getCustomQueryColumns();
        } else {
            $columns = $this->dataSetColumnFactory->getByDataSetId($this->getOption('dataSetId'));
        }
        $columnsNotSelected = [];
        $colIds = explode(',', $this->getOption('columns'));

        foreach ($columns as $column) {
            /* @var DataSetColumn $column */
            if (!in_array($column->dataSetColumnId, $colIds))
                $columnsNotSelected[] = $column;
        }
        return $columnsNotSelected;
    }

    /**
     * Get the Order Clause
     * @return mixed
     */
    public function getOrderClause()
    {
        return json_decode($this->getOption('orderClauses', "[]"), true);
    }

    /**
     * Get the Filter Clause
     * @return mixed
     */
    public function getFilterClause()
    {
        return json_decode($this->getOption('filterClauses', "[]"), true);
    }

    /** @inheritdoc */
    public function getExtra()
    {
        return [
            'templates' => $this->templatesAvailable(),
            'orderClause' => $this->getOrderClause(),
            'filterClause' => $this->getFilterClause(),
            'columns' => $this->dataSetColumns(),
            'dataSet' => ($this->getOption('dataSetId', 0) != 0) ? $this->dataSetFactory->getById($this->getOption('dataSetId')) : null
        ];
    }

    /** @inheritdoc @override */
    public function editForm(Request $request)
    {
        $sanitizedParams = $this->getSanitizer($request->getParams());
        // Do we have a step provided?
        $step = $sanitizedParams->getInt('step', ['default' => 2]);
        if ($step == 1 || !$this->hasDataSet() && $this->getOption('useQuery') != '1') {
            return 'datasetview-form-edit-step1';
        } else {
            return 'datasetview-form-edit';
        }
    }

    /**
     * @SWG\Put(
     *  path="/playlist/widget/{widgetId}?dataSetView",
     *  operationId="widgetDataSetViewEdit",
     *  tags={"widget"},
     *  summary="Edit a dataSetView Widget",
     *  description="Edit an existing dataSetView Widget. This call will replace existing Widget object, all not supplied parameters will be set to default.",
     *  @SWG\Parameter(
     *      name="widgetId",
     *      in="path",
     *      description="The WidgetId to Edit",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="step",
     *      in="formData",
     *      description="The Step Number being edited",
     *      type="integer",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="name",
     *      in="formData",
     *      description="Optional Widget Name",
     *      type="string",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="dataSetId",
     *      in="formData",
     *      description="For Step 1. Create dataSetView Widget using provided dataSetId of an existing dataSet",
     *      type="integer",
     *      required=true
     *  ),
     *  @SWG\Parameter(
     *      name="dataSetColumnId",
     *      in="formData",
     *      description="Array of dataSetColumn IDs to assign",
     *      type="array",
     *      required=false,
     *      @SWG\Items(type="integer")
     *   ),
     *  @SWG\Parameter(
     *      name="duration",
     *      in="formData",
     *      description="The dataSetView Duration",
     *      type="integer",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="useDuration",
     *      in="formData",
     *      description="Select 1 only if you will provide duration parameter as well",
     *      type="integer",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="enableStat",
     *      in="formData",
     *      description="The option (On, Off, Inherit) to enable the collection of Widget Proof of Play statistics",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="updateInterval",
     *      in="formData",
     *      description="Update interval in seconds",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="freshnessTimeout",
     *      in="formData",
     *      description="How long should a Player in minutes show content before switching to the No Data Template?",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="rowsPerPage",
     *      in="formData",
     *      description="Number of rows per page, 0 for no pages",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="showHeadings",
     *      in="formData",
     *      description="Should the table show Heading? (0,1)",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="upperLimit",
     *      in="formData",
     *      description="Upper low limit for this dataSet, 0 for no limit",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="lowerLimit",
     *      in="formData",
     *      description="Lower low limit for this dataSet, 0 for no limit",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="filter",
     *      in="formData",
     *      description="SQL clause for filter this dataSet",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="ordering",
     *      in="formData",
     *      description="SQL clause for how this dataSet should be ordered",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="templateId",
     *      in="formData",
     *      description="Template you'd like to apply, options available: empty, light-green",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="overrideTemplate",
     *      in="formData",
     *      description="flag (0, 1) override template checkbox",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="useOrderingClause",
     *      in="formData",
     *      description="flag (0,1) Use advanced order clause - set to 1 if ordering is provided",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="useFilteringClause",
     *      in="formData",
     *      description="flag (0,1) Use advanced filter clause - set to 1 if filter is provided",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="noDataMessage",
     *      in="formData",
     *      description="A message to display when no data is returned from the source",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="noDataMessage_advanced",
     *      in="formData",
     *      description="A flag (0, 1), Should text area by presented as a visual editor?",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=204,
     *      description="successful operation"
     *  )
     * )
     *
     * @inheritdoc
     */
    public function edit(Request $request, Response $response): Response
    {
        $sanitizedParams = $this->getSanitizer($request->getParams());
        // Do we have a step provided?
        $step = $sanitizedParams->getInt('step', ['default' => 2]);

        if ($step == 1) {

            // Read in the dataSetId, validate and store it
            $dataSetId = $sanitizedParams->getInt('dataSetId');
            $customHost = $sanitizedParams->getString('customHost');
            $customDBName = $sanitizedParams->getString('customDBName');
            $customDBUser = $sanitizedParams->getString('customDBUser');
            $customDBPassword = $sanitizedParams->getString('customDBPassword');
            $customSql = $sanitizedParams->getString('customSql');
            $useQuery = $sanitizedParams->getCheckBox('useQuery');

            if ($useQuery) {
                $pdo = new PdoStorageService();
                try {
                    $pdo->connect($customHost, $customDBUser, $customDBPassword, $customDBName);
                    if ($pdo->exists($customSql, []) == true) {
                        $this->setOption('customHost', $customHost);
                        $this->setOption('customDBUser', $customDBUser);
                        $this->setOption('customDBPassword', $customDBPassword);
                        $this->setOption('customDBName', $customDBName);
                        $this->setOption('customSql', $customSql);
                        $this->setOption('useQuery', $useQuery);
                        $this->setOption('dataSetId', 0);
                    }
                } catch (\PDOException $e) {
                    throw new GeneralException('[edit] Custom Database connection Faied. '.$e->getMessage());
                }
            } else {
                // Do we already have a DataSet?
                if ($this->hasDataSet() && $dataSetId != $this->getOption('dataSetId')) {
                    // Reset the fields that are dependent on the dataSetId
                    $this->setOption('columns', '');
                }

                $this->setOption('dataSetId', $dataSetId);

                // Validate Data Set Selected
                if ($dataSetId == 0) {
                    throw new InvalidArgumentException(__('Please select a DataSet'), 'dataSetId');
                }
                //$this->setOption('customHost', '');
                //$this->setOption('customDBUser', '');
                //$this->setOption('customDBPassword', '');
                //$this->setOption('customDBName', '');
                //$this->setOption('customSql', '');
                $this->setOption('useQuery', '0');

                // Check we have permission to use this DataSetId
                if (!$this->getUser()->checkViewable($this->dataSetFactory->getById($this->getOption('dataSetId')))) {
                    throw new InvalidArgumentException(__('You do not have permission to use that dataset'), 'dataSetId');
                }
            }

        } else {

            // Columns
            if ($this->getOption('useQuery') == '1')
                $columns = $sanitizedParams->getArray('dataSetColumnId', ['default' => []]);
            else
                $columns = $sanitizedParams->getIntArray('dataSetColumnId', ['default' => []]);

            if (count($columns) == 0) {
                $this->setOption('columns', '');
            } else {
                $this->setOption('columns', implode(',', $columns));
            }

            // Other properties
            $this->setOption('customField', $sanitizedParams->getString('customfield'));
            $this->setOption('threshold', $sanitizedParams->getString('thresholdjson'));
            $this->setOption('dateTimeFormat', $sanitizedParams->getString('DateTimeFormat'));
            $this->setOption('enableCustomTrigger', $sanitizedParams->getCheckbox('enableCustomTrigger'));
            $this->setOption('triggerDataSetId', $sanitizedParams->getString('triggerDataSetId'));
            $this->setOption('triggerColumn', $sanitizedParams->getString('triggerColumn'));
            $this->setOption('triggerCondition', $sanitizedParams->getString('triggerCondition'));
            $this->setOption('triggerValue', $sanitizedParams->getString('triggerValue'));

            $this->setOption('name', $sanitizedParams->getString('name'));
            $this->setUseDuration($sanitizedParams->getCheckbox('useDuration'));
            $this->setDuration($sanitizedParams->getInt('duration', ['default' => $this->getDuration()]));
            $this->setOption('enableStat', $sanitizedParams->getString('enableStat'));
            $this->setOption('updateInterval', $sanitizedParams->getInt('updateInterval', ['default' => 300]));//The unit of updateinterval is changed to sec from min. 
            $this->setOption('freshnessTimeout', $sanitizedParams->getInt('freshnessTimeout', ['default' => 0]));
            $this->setOption('rowsPerPage', $sanitizedParams->getInt('rowsPerPage'));
            $this->setOption('durationIsPerPage', $sanitizedParams->getCheckbox('durationIsPerPage'));
            $this->setOption('showHeadings', $sanitizedParams->getCheckbox('showHeadings'));
            $this->setOption('upperLimit', $sanitizedParams->getInt('upperLimit', ['default' => 0]));
            $this->setOption('lowerLimit', $sanitizedParams->getInt('lowerLimit', ['default' => 0]));
            $this->setOption('filter', $request->getParam('filter', null));
            $this->setOption('ordering', $sanitizedParams->getString('ordering'));
            $this->setOption('templateId', $sanitizedParams->getString('templateId'));
            $this->setOption('overrideTemplate', $sanitizedParams->getCheckbox('overrideTemplate'));
            $this->setOption('optionalHtml', $sanitizedParams->getCheckbox('optionalHtml'));
            $this->setOption('useOrderingClause', $sanitizedParams->getCheckbox('useOrderingClause'));
            $this->setOption('useFilteringClause', $sanitizedParams->getCheckbox('useFilteringClause'));
            $this->setRawNode('noDataMessage', $request->getParam('noDataMessage', ''));
            $this->setOption('noDataMessage_advanced', $sanitizedParams->getCheckbox('noDataMessage_advanced'));
            $this->setRawNode('optionalHtmlMessage', $request->getParam('optionalHtmlMessage', ''));
            $this->setOption('optionalHtmlMessage_advanced', $sanitizedParams->getCheckbox('optionalHtmlMessage_advanced'));
            $this->setRawNode('javaScript', $request->getParam('javaScript', ''));

            $this->setOption('backgroundColor', $sanitizedParams->getString('backgroundColor'));
            $this->setOption('alterBackColor', $sanitizedParams->getString('alterBackColor'));
            $this->setOption('setEvenColor', $sanitizedParams->getCheckbox('setEvenColor'));
            $this->setOption('backgroundColorHeader', $sanitizedParams->getString('backgroundColor_header'));
            $this->setOption('borderColor', $sanitizedParams->getString('borderColor'));
            $this->setOption('fontColor', $sanitizedParams->getString('fontColor'));
            $this->setOption('fontColorHeader', $sanitizedParams->getString('fontColor_header'));
            $this->setOption('fontFamily', $sanitizedParams->getString('fontFamily'));
            $this->setOption('fontFamilyHeader', $sanitizedParams->getString('fontFamily_header'));
            $this->setOption('fontSize', $sanitizedParams->getInt('fontSize'));
            $this->setOption('fontSizeHeader', $sanitizedParams->getInt('fontSize_header'));

            if ($this->getOption('overrideTemplate') == 1) {
                $this->setRawNode('styleSheet', $request->getParam('styleSheet', null));
            }

            if($this->getOption('optionalHtml') == 1) {
                $this->setRawNode('optionalHtmlMessage', $request->getParam('optionalHtmlMessage', null));
            }
            // Order and Filter criteria
            $orderClauses = $sanitizedParams->getArray('orderClause', ['default' => []]);
            $orderClauseDirections = $sanitizedParams->getArray('orderClauseDirection', ['default' => []]);
            $orderClauseMapping = [];

            $i = -1;
            foreach ($orderClauses as $orderClause) {
                $i++;

                if ($orderClause == '')
                    continue;

                // Map the stop code received to the stop ref (if there is one)
                $orderClauseMapping[] = [
                    'orderClause' => $orderClause,
                    'orderClauseDirection' => isset($orderClauseDirections[$i]) ? $orderClauseDirections[$i] : '',
                ];
            }

            $this->setOption('orderClauses', json_encode($orderClauseMapping));

            $filterClauses = $sanitizedParams->getArray('filterClause', ['default' => []]);
            $filterClauseOperator = $sanitizedParams->getArray('filterClauseOperator');
            $filterClauseCriteria = $sanitizedParams->getArray('filterClauseCriteria');
            $filterClauseValue = $sanitizedParams->getArray('filterClauseValue');
            $filterClauseMapping = [];

            $i = -1;
            foreach ($filterClauses as $filterClause) {
                $i++;

                if ($filterClause == '')
                    continue;

                // Map the stop code received to the stop ref (if there is one)
                $filterClauseMapping[] = [
                    'filterClause' => $filterClause,
                    'filterClauseOperator' => isset($filterClauseOperator[$i]) ? $filterClauseOperator[$i] : '',
                    'filterClauseCriteria' => isset($filterClauseCriteria[$i]) ? $filterClauseCriteria[$i] : '',
                    'filterClauseValue' => isset($filterClauseValue[$i]) ? $filterClauseValue[$i] : '',
                ];
            }

            $this->setOption('filterClauses', json_encode($filterClauseMapping));

            // Validate
            $this->isValid();
        }

        // Save the widget
        $this->saveWidget();

        return $response;
    }

    /**
     * @inheritDoc
     */
    public function getResource($displayId = 0)
    {
        // Build the response
        $this
            ->initialiseGetResource()
            ->appendViewPortWidth($this->region->width)
            ->appendJavaScriptFile('vendor/jquery.min.js')
            ->appendJavaScriptFile('vendor/jquery-cycle-2.1.6.min.js')
            ->appendJavaScriptFile('vendor/moment.js')
            ->appendJavaScriptFile('xibo-layout-scaler.js')
            ->appendJavaScriptFile('xibo-dataset-render.js')
            ->appendJavaScriptFile('xibo-image-render.js')
            ->appendJavaScript('var xiboICTargetId = ' . $this->getWidgetId() . ';')
            ->appendJavaScriptFile('xibo-interactive-control.min.js')
            ->appendJavaScript('xiboIC.lockAllInteractions();')
            ->appendFontCss()
            ->appendCss(file_get_contents($this->getConfig()->uri('css/client.css', true)))
        ;

        // Get CSS from the original template or from the input field
        $styleSheet = '';

        if ($this->getOption('overrideTemplate', 1) == 0) {

            $template = $this->getTemplateById($this->getOption('templateId'));

            if (isset($template)) {
                $styleSheet = $template['css'];
            }
        } else {
            $styleSheet = $this->getRawNode('styleSheet', '');
        }

        // Get the embedded HTML out of RAW
        $styleSheet = $this->parseLibraryReferences($this->isPreview(), $styleSheet);

        // If we have some options then add them to the end of the style sheet
        if ($this->getOption('backgroundColor') != '' && $this->getOption('templateId') == 'empty') {
            (bool)$this->getOption('setEvenColor') 
                ? $styleSheet .= 'table.DataSetTable tbody tr:nth-child(odd) { background-color: ' . $this->getOption('backgroundColor') . '; }'
                                .'table.DataSetTable tbody tr:nth-child(even) { background-color: ' . $this->getOption('alterBackColor') . '; }'
                : $styleSheet .= 'table.DataSetTable tbody tr { background-color: ' . $this->getOption('backgroundColor') . '; }';
        }
        if ($this->getOption('backgroundColorHeader') != '' && $this->getOption('templateId') == 'empty') {
            $styleSheet .= 'table.DataSetTable thead { background-color: ' . $this->getOption('backgroundColorHeader') . '; }';
        }
        if ($this->getOption('borderColor') != '' && $this->getOption('templateId') == 'empty') {
            $styleSheet .= 'table.DataSetTable, table.DataSetTable tr, table.DataSetTable th, table.DataSetTable td { border: 1px solid ' . $this->getOption('borderColor') . '; }';
        }
        if ($this->getOption('fontColor') != '' && $this->getOption('templateId') == 'empty') {
            $styleSheet .= 'table.DataSetTable tbody { color: ' . $this->getOption('fontColor') . '; }';
        }
        if ($this->getOption('fontColorHeader') != '' && $this->getOption('templateId') == 'empty') {
            $styleSheet .= 'table.DataSetTable thead { color: ' . $this->getOption('fontColorHeader') . '; }';
        }
        if ($this->getOption('fontFamily') != '') {
            $styleSheet .= 'table.DataSetTable tbody { font-family: ' . $this->getOption('fontFamily') . '; }';
        }
        if ($this->getOption('fontFamilyHeader') != '') {
            $styleSheet .= 'table.DataSetTable thead { font-family: ' . $this->getOption('fontFamilyHeader') . '; }';
        }
        if ($this->getOption('fontSize') != '') {
            $styleSheet .= 'table.DataSetTable tbody { font-size: ' . $this->getOption('fontSize') . 'px; }';
        }
        if ($this->getOption('fontSizeHeader') != '') {
            $styleSheet .= 'table.DataSetTable thead { font-size: ' . $this->getOption('fontSizeHeader') . 'px; }';
        }

        // Table display CSS fix
        $styleSheet .= 'table.DataSetTable.cycle-slide { display: table !important; }';

        // Calculate duration
        $duration = $this->getCalculatedDurationForGetResource();
        $durationIsPerItem = $this->getOption('durationIsPerPage', 1);
        $rowsPerPage = $this->getOption('rowsPerPage', 0);

        // If we are going to cycle between pages, make sure we hide all of the tables initially.
        if ($rowsPerPage > 0) {
            $styleSheet .= 'table.DataSetTable {visibility:hidden;}';
        }

        // Generate the table
        $table = $this->dataSetTableHtml($displayId);
        // Work out how many pages we will be showing.
        $pages = ceil($table['countPages']);
        $totalDuration = ($durationIsPerItem == 0) ? $duration : ($duration * $pages);

        // Replace and Control Meta options
        $this
            ->appendControlMeta('NUMITEMS', $pages)
            ->appendControlMeta('DURATION', $totalDuration)
            ->appendCss($styleSheet)
            ->appendOptions([
                'type' => $this->getModuleType(),
                'duration' => $duration,
                'originalWidth' => $this->region->width,
                'originalHeight' => $this->region->height,
                'rowsPerPage' => $rowsPerPage,
                'durationIsPerItem' => (($durationIsPerItem == 0) ? false : true),
                'generatedOn' => Carbon::now()->format('c'),
                'freshnessTimeout' => $this->getOption('freshnessTimeout', 0),
                'noDataMessage' => $this->noDataMessageOrDefault('')['html'],
                'updatesInterval' => $this->getOption('updateInterval', 30000),
                'enableCustomTrigger' => $this->getOption('enableCustomTrigger'),
                'triggerDataSetId' => $this->getOption('triggerDataSetId'),
                'triggerColumn' => $this->getOption('triggerColumn'),
                'triggerCondition' => $this->getOption('triggerCondition'),
                'triggerValue' => $this->getOption('triggerValue'),
                'widgetId' => $this->getWidgetId()
            ])
            ->appendJavaScript('
                var dsTriggerTimer = 0;
                function setThresholdColor(){
                    let initalThreshold ='.$this->getOption('threshold').';
                    Object.keys(initalThreshold).reverse().forEach(function(key){
                        console.log(initalThreshold[key].value);
                        $("table td[data-head=\'"+ initalThreshold[key].column + "\'] span")
                            .filter(function(index){
                                switch(initalThreshold[key].compare)
                             {
                             	case "less than": 
                                    return parseInt(this.innerHTML) < initalThreshold[key].value;
                                case "greater than": 
                                    return parseInt(this.innerHTML) > initalThreshold[key].value;
                                case "equals": 
                                    return parseInt(this.innerHTML) == initalThreshold[key].value;
                             }
                            })
                            .css("color", initalThreshold[key].color)
                    })
                }
                function checkCustomTrigger() {
                    $.ajax({
                        type: "get",
                        url: `'.$this->urlFor('module.widget.dataset.checkCustomTrigger').'?triggerDataSetId=${options.triggerDataSetId}&triggerColumn=${options.triggerColumn}&triggerCondition=${options.triggerCondition}&triggerValue=${options.triggerValue}`,
                    }).done(function(res) {
                        let data = res.data;
                        if(res.success) {
                            if (data == "false") {
                                if (typeof parent.previewActionTrigger == "function"){
                                    parent.previewActionTrigger("/remove", {id: options.widgetId});
                                    dsTriggerTimer && clearInterval(dsTriggerTimer);
                                }
                            }
                        }
                    });
                }
                $(document).ready(function() {
                    $("body").xiboLayoutScaler(options);
                    $("#DataSetTableContainer").find("img").xiboImageRender(options);

                    let runOnVisible = function() { $("#DataSetTableContainer").dataSetRender(options);  }; 
                    (xiboIC.checkVisible()) ? runOnVisible() : xiboIC.addToQueue(runOnVisible);

                    setInterval(() => {
                        window.location.reload();
                    }, options.updatesInterval * 1000);
                    
                    if (options.enableCustomTrigger) {
                        dsTriggerTimer = setInterval(() => {
                            checkCustomTrigger();
                        }, 2000);
                    }
                    // Do we have a freshnessTimeout?
                    if (options.freshnessTimeout > 0) {
                        // Set up an interval to check whether or not we have exceeded our freshness
                        var timer = setInterval(function() {
                            if (moment(options.generatedOn).add(options.freshnessTimeout, \'minutes\').isBefore(moment())) {
                                $("#content").empty().append(options.noDataMessage);
                                clearInterval(timer);
                            }
                        }, 10000);
                    }
                    setThresholdColor();
                });
            ')
            ->appendJavaScript($this->parseLibraryReferences($this->isPreview(), $this->getRawNode('javaScript', '')))
            ->appendBody($table['html']);

        return $this->finaliseGetResource();
    }

    /**
     * Get the Data Set Table
     * @param int $displayId
     * @return array
     * @throws InvalidArgumentException
     * @throws \Xibo\Support\Exception\ConfigurationException
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     */
    private function dataSetTableHtml($displayId = 0)
    {
        // Show a preview of the data set table output.
        $dataSetId = $this->getOption('dataSetId');
        $upperLimit = $this->getOption('upperLimit');
        $lowerLimit = $this->getOption('lowerLimit');
        $columnIds = $this->getOption('columns');
        $showHeadings = $this->getOption('showHeadings');
        $rowsPerPage = $this->getOption('rowsPerPage');
        $dataTimeFormatPattern = $this->getOption('dateTimeFormat');

        if ($columnIds == '') {
            return $this->noDataMessageOrDefault(__('No columns'));
        }

        //optionalHtmlMessage
        if($this->getOption('optionalHtml') == 1) {
            return $this->optionalHtmlMessageOrDefault();
        }
        // Ordering
        $ordering = '';

        if ($this->getOption('useOrderingClause', 1) == 1) {
            $ordering = $this->getOption('ordering');
        } else {
            // Build an order string
            foreach (json_decode($this->getOption('orderClauses', '[]'), true) as $clause) {
                $ordering .= $clause['orderClause'] . ' ' . $clause['orderClauseDirection'] . ',';
            }

            $ordering = rtrim($ordering, ',');
        }

        // Filtering
        $filter = '';

        if ($this->getOption('useFilteringClause', 1) == 1) {
            $filter = $this->getOption('filter');
        } else {
            // Build
            $i = 0;
            foreach (json_decode($this->getOption('filterClauses', '[]'), true) as $clause) {
                $i++;
                $criteria = '';

                switch ($clause['filterClauseCriteria']) {

                    case 'starts-with':
                        $criteria = 'LIKE \'' . $clause['filterClauseValue'] . '%\'';
                        break;

                    case 'ends-with':
                        $criteria = 'LIKE \'%' . $clause['filterClauseValue'] . '\'';
                        break;

                    case 'contains':
                        $criteria = 'LIKE \'%' . $clause['filterClauseValue'] . '%\'';
                        break;

                    case 'equals':
                        $criteria = '= \'' . $clause['filterClauseValue'] . '\'';
                        break;

                    case 'not-contains':
                        $criteria = 'NOT LIKE \'%' . $clause['filterClauseValue'] . '%\'';
                        break;

                    case 'not-starts-with':
                        $criteria = 'NOT LIKE \'' . $clause['filterClauseValue'] . '%\'';
                        break;

                    case 'not-ends-with':
                        $criteria = 'NOT LIKE \'%' . $clause['filterClauseValue'] . '\'';
                        break;

                    case 'not-equals':
                        $criteria = '<> \'' . $clause['filterClauseValue'] . '\'';
                        break;

                    case 'greater-than':
                        $criteria = '> \'' . $clause['filterClauseValue'] . '\'';
                        break;

                    case 'less-than':
                        $criteria = '< \'' . $clause['filterClauseValue'] . '\'';
                        break;

                    default:
                        continue 2;
                }

                if ($i > 1)
                    $filter .= ' ' . $clause['filterClauseOperator'] . ' ';

                $filter .= $clause['filterClause'] . ' ' . $criteria;
            }
        }

        // Array of columnIds we want
        $columnIds = explode(',', $columnIds);

        // Set an expiry time for the media
        $expires = Carbon::now()->addSeconds($this->getOption('updateInterval', 300))->format('U');

        // Create a data set object, to get the results.
        try {
            if ($this->getOption('useQuery') == '1') {
                $columns = $this->getCustomQueryColumns();
                $customHeading = (array)json_decode($this->getOption('customField'));
                $mappings = [];
                foreach ($columns as $c) {
                    /* @var DataSetColumn $column */
                    $customHeading[$c->heading] = isset($customHeading[$c->heading])
                                                            ?$customHeading[$c->heading]
                                                            :null;
                    if (in_array($c->dataSetColumnId, $columnIds)) {
                        $mappings[] = [
                            'dataSetColumnId' => $c->dataSetColumnId,
                            'customHeading' => is_null($customHeading[$c->heading])? $c->heading : $customHeading[$c->heading],
                            'heading' => $c->dataSetColumnId,
                            'dataTypeId' => 1
                        ];
                    }
                }
            } else {
                $dataSet = $this->dataSetFactory->getById($dataSetId);
                $customHeading = (array)json_decode($this->getOption('customField'));

                // Get an array representing the id->heading mappings
                $mappings = [];
                foreach ($columnIds as $dataSetColumnId) {
                    // Get the column definition this represents
                    $column = $dataSet->getColumn($dataSetColumnId);
                    /* @var DataSetColumn $column */
                    $customHeading[$column->heading] = isset($customHeading[$column->heading])
                                                            ?$customHeading[$column->heading]
                                                            :null;

                    $mappings[] = [
                        'dataSetColumnId' => $dataSetColumnId,
                        'customHeading' => is_null($customHeading[$column->heading])? $column->heading : $customHeading[$column->heading],
                        'heading' => $column->heading,
                        'dataTypeId' => $column->dataTypeId
                    ];
                }
            }

            $this->getLog()->debug(sprintf('Resolved column mappings: %s', json_encode($columnIds)));

            $filter = [
                'filter' => $filter,
                'order' => $ordering,
                'displayId' => $displayId
            ];

            // limits?
            if ($lowerLimit != 0 || $upperLimit != 0) {
                // Start should be the lower limit
                // Size should be the distance between upper and lower
                $filter['start'] = $lowerLimit;
                $filter['size'] = $upperLimit - $lowerLimit;
            }

            // Set the timezone for SQL
            $dateNow = Carbon::now();
            if ($displayId != 0) {
                $display = $this->displayFactory->getById($displayId);
                $timeZone = $display->getSetting('displayTimeZone', '');
                $timeZone = ($timeZone == '') ? $this->getConfig()->getSetting('defaultTimezone') : $timeZone;
                $dateNow->timezone($timeZone);
                $this->getLog()->debug(sprintf('Display Timezone Resolved: %s. Time: %s.', $timeZone, $dateNow->toDateTimeString()));
            }

            $this->getStore()->setTimeZone($dateNow->format('P'));

            // Get the data (complete table, filtered)
            if ($this->getOption('useQuery') == '1') {
                $dataSetResults = $this->getCustomData();
            } else {
                $dataSetResults = $dataSet->getData($filter);
            }

            if (count($dataSetResults) <= 0) {
                return $this->noDataMessageOrDefault();
            }

            $rowCount = 1;
            $rowCountThisPage = 1;
            $totalRows = count($dataSetResults);
            
            if ($rowsPerPage > 0)
            $totalPages = $totalRows / $rowsPerPage;
            else
            $totalPages = 1;
            
            $table = '<div id="DataSetTableContainer" totalRows="' . $totalRows . '" totalPages="' . $totalPages . '">';
            
            // Parse each result and
            foreach ($dataSetResults as $row) {
                if (($rowsPerPage > 0 && $rowCountThisPage >= $rowsPerPage) || $rowCount == 1) {
                    
                    // Reset the row count on this page
                    $rowCountThisPage = 0;

                    if ($rowCount > 1) {
                        $table .= '</tbody>';
                        $table .= '</table>';
                    }

                    // Output the table header
                    $table .= '<table class="DataSetTable">';

                    if ($showHeadings == 1) {
                        $table .= '<thead>';
                        $table .= ' <tr class="HeaderRow">';

                        foreach ($mappings as $mapping) {
                            $table .= '<th class="DataSetColumnHeaderCell">' . $mapping['customHeading'] . '</th>';
                        }

                        $table .= ' </tr>';
                        $table .= '</thead>';
                    }

                    $table .= '<tbody>';
                }

                $table .= '<tr class="DataSetRow DataSetRow' . (($rowCount % 2) ? 'Odd"' : 'Even"') .' id="row_' . $rowCount . '">';

                // Output each cell for these results
                $i = 0; 
                foreach ($mappings as $mapping) {
                    $i++;

                    // Pull out the cell for this row / column
                    $replace = $row[$mapping['heading']];

                    // If the value is empty, then move on (don't do so for 0)
                    if ($replace === '') {
                        // We don't do anything there, we just output an empty column.
                        $replace = '';

                    } else if ($mapping['dataTypeId'] == 4) {
                        // What if this column is an image column type?

                        // Grab the external image
                        $file = $this->mediaFactory->queueDownload('datasetview_' . md5($dataSetId . $mapping['dataSetColumnId'] . $replace), str_replace(' ', '%20', htmlspecialchars_decode($replace)), $expires);

                        $replace = ($this->isPreview())
                            ? '<img src="' . $this->urlFor('library.download', ['id' => $file->mediaId, 'type' => 'image']) . '?preview=1" />'
                            : '<img src="' . $file->storedAs . '" />';

                    } else if ($mapping['dataTypeId'] == 5) {

                        // Library Image
                        // The content is the ID of the image
                        try {
                            $file = $this->mediaFactory->getById($replace);

                            // Already in the library - assign this mediaId to the Layout immediately.
                            $this->assignMedia($file->mediaId);
                        }
                        catch (NotFoundException $e) {
                            $this->getLog()->error(sprintf('Library Image [%s] not found in DataSetId %d.', $replace, $dataSetId));
                            continue;
                        }

                        $replace = ($this->isPreview())
                            ? '<img src="' . $this->urlFor('library.download', ['id' => $file->mediaId, 'type' => 'image']) . '?preview=1" />'
                            : '<img src="' . $file->storedAs . '" />';
                    }

                    if(strlen(trim(preg_replace('/\xc2\xa0/',' ',$dataTimeFormatPattern))) != 0 && preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}\s[0-9]{2}:[0-9]{2}/", $replace)){
                        $replace = date_create($replace); 
                        $replace =date_format($replace, trim($dataTimeFormatPattern)); 
                    }

                    $table .= '<td data-head="'.$mapping['heading'].'" class="DataSetColumn DataSetColumn_' . $i . '" id="column_' . ($i + 1) . '"><span class="DataSetCellSpan DataSetCellSpan_' . $rowCount . '_' . $i .'" id="span_' . $rowCount . '_' . ($i + 1) . '">' . $replace . '</span></td>';
                }

                // Process queued downloads
                $this->mediaFactory->processDownloads(function($media) {
                    // Success
                    $this->getLog()->debug('Successfully downloaded ' . $media->mediaId);

                    // Tag this layout with this file
                    $this->assignMedia($media->mediaId);
                });

                $table .= '</tr>';

                $rowCount++;
                $rowCountThisPage++;
            }

            $table .= '</tbody>';
            $table .= '</table>';
            $table .= '</div>';

            return [
                'html' => $table,
                'countRows' => $totalRows,
                'countPages' => $totalPages
            ];
        }
        catch (NotFoundException $e) {
            $this->getLog()->info(sprintf('Request failed for dataSet id=%d. Widget=%d. Due to %s', $dataSetId, $this->getWidgetId(), $e->getMessage()));
            $this->getLog()->debug($e->getTraceAsString());

            return $this->noDataMessageOrDefault();
        }
    }

    /**
     * @param string|null $default
     * @return array
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    private function noDataMessageOrDefault($default = null)
    {
        if ($default === null) {
            $default = __('Empty Result Set with filter criteria.');
        }

        if ($this->getRawNode('noDataMessage') == '') {
            throw new NotFoundException($default);
        } else {
            return [
                'html' => $this->getRawNode('noDataMessage'),
                'countPages' => 1,
                'countRows' => 1
            ];
        }
    }

    /**
     * @param string|null $default
     * @return array
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    private function optionalHtmlMessageOrDefault($default = null)
    {
        $dataSetId = $this->getOption('dataSetId');
        $columnIds = $this->getOption('columns');
        $optionalHtmlMessage = $this->getOption('optionalHtmlMessage');

        $columnIds = explode(',', $columnIds);

        if ($default === null) {
            $default = __('Empty Result Set with filter criteria.');
        }

        if ($this->getRawNode('optionalHtmlMessage') == '') {
            throw new NotFoundException($default);
        } else{
                try {
                    if ($this->isCustomQuery()) {
                        $columns = $this->getCustomQueryColumns();
                        $dataSetResults = $this->getCustomData();
                        foreach($columns as $column) {
                            $optionalHtmlMessage = str_replace('['. $column->heading .']', $dataSetResults[0][$column->heading], $optionalHtmlMessage);
                        }
                    } else {
                        $dataSet = $this->dataSetFactory->getById($dataSetId);
                        $dataSetResults = $dataSet->getData();

                        foreach($columnIds as $dataSetColumnId) {
                            $column = $dataSet->getColumn($dataSetColumnId);
                            $optionalHtmlMessage = str_replace('['. $column->heading .']', $dataSetResults[0][$column->heading], $optionalHtmlMessage);
                        }
                    }
                }
                catch (NotFoundException $e) {
                    $this->getLog()->info(sprintf('Request failed for dataSet id=%d. Widget=%d. Due to %s', $dataSetId, $this->getWidgetId(), $e->getMessage()));
                    $this->getLog()->debug($e->getTraceAsString());
                    throw new GeneralException('[optionalHtmlMessageOrDefault] Column Data not Found. '.$e->getMessage());
                }
        }
        return [
            'html' => $optionalHtmlMessage,
            'countPages' => 1,
            'countRows' => 1
        ];
    }

    /**
     * Does this module have a DataSet yet?
     * @return bool
     */
    private function hasDataSet()
    {
        return $this->getOption('useQuery') == '1' || (v::notEmpty()->validate($this->getOption('dataSetId')));
    }

    /** @inheritdoc */
    public function isValid()
    {
        if ($this->getUseDuration() == 1 && $this->getDuration() == 0)
            throw new InvalidArgumentException(__('Please enter a duration'), 'duration');

        if (!is_numeric($this->getOption('upperLimit')) || !is_numeric($this->getOption('lowerLimit')))
            throw new InvalidArgumentException(__('Limits must be numbers'), 'limit');

        if ($this->getOption('upperLimit') < 0 || $this->getOption('lowerLimit') < 0)
            throw new InvalidArgumentException(__('Limits cannot be lower than 0'), 'limit');

        // Check the bounds of the limits
        if ($this->getOption('upperLimit') != 0 && $this->getOption('upperLimit') < $this->getOption('lowerLimit'))
            throw new InvalidArgumentException(__('Upper limit must be higher than lower limit'), 'limit');

        if ($this->getOption('updateInterval') !== null && !v::intType()->min(0)->validate($this->getOption('updateInterval', 0)))
            throw new InvalidArgumentException(__('Update Interval must be greater than or equal to 0'), 'updateInterval');

        // Make sure we haven't entered a silly value in the filter
        if (strstr($this->getOption('filter'), 'DESC'))
            throw new InvalidArgumentException(__('Cannot user ordering criteria in the Filter Clause'), 'filter');

        return ($this->hasDataSet()) ? self::$STATUS_VALID : self::$STATUS_INVALID;
    }

    /** @inheritdoc */
    public function getModifiedDate($displayId)
    {
        $widgetModifiedDt = $this->widget->modifiedDt;

        if ($this->getOption('useQuery') != '1') {
            $dataSetId = $this->getOption('dataSetId');
            $dataSet = $this->dataSetFactory->getById($dataSetId);

            // Set the timestamp
            $widgetModifiedDt = ($dataSet->lastDataEdit > $widgetModifiedDt) ? $dataSet->lastDataEdit : $widgetModifiedDt;

            // Remote dataSets are kept "active" by required files
            $dataSet->setActive();
        }

        return Carbon::createFromTimestamp($widgetModifiedDt);
    }

    /** @inheritdoc */
    public function getCacheDuration()
    {
        return $this->getOption('updateInterval', 300);
    }

    /** @inheritdoc */
    public function getCacheKey($displayId)
    {
        // DataSetViews are display specific
        return $this->getWidgetId() . '_' . $displayId;
    }

    /** @inheritdoc */
    public function isCacheDisplaySpecific()
    {
        return true;
    }

    /** @inheritdoc */
    public function getLockKey()
    {
        // Lock to the dataSetId, because our dataSet might have external images which are downloaded.
        return $this->getOption('dataSetId');
    }

    /** @inheritDoc */
    public function hasTemplates()
    {
        return true;
    }
}
