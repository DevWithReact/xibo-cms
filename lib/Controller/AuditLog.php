<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2013 Daniel Garner
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
namespace Xibo\Controller;

class AuditLog extends Base
{
    public function displayPage()
    {
        $this->getState()->template = 'auditlog-page';
    }

    function grid()
    {
        $filterFromDt = $this->getSanitizer()->getDate('fromDt');
        $filterToDt = $this->getSanitizer()->getDate('toDt');
        $filterUser = $this->getSanitizer()->getString('user');
        $filterEntity = $this->getSanitizer()->getString('entity');

        $search = [];

        // Get the dates and times
        if ($filterFromDt == null)
            $filterFromDt = $this->getDate()->parse()->sub('1 day');

        if ($filterToDt == null)
            $filterToDt = $this->getDate()->parse();

        $search['fromTimeStamp'] = $filterFromDt->format('U');
        $search['toTimeStamp'] = $filterToDt->format('U');

        if ($filterUser != '')
            $search['userName'] = $filterUser;

        if ($filterEntity != '')
            $search['entity'] = $filterEntity;

        $rows = $this->getFactoryService()->get('AuditLogFactory')->query($this->gridRenderSort(), $this->gridRenderFilter($search));

        // Do some post processing
        foreach ($rows as $row) {
            /* @var \Xibo\Entity\AuditLog $row */
            $row->objectAfter = json_decode($row->objectAfter);
        }

        $this->getState()->template = 'grid';
        $this->getState()->recordsTotal = $this->getFactoryService()->get('AuditLogFactory')->countLast();
        $this->getState()->setData($rows);
    }

    /**
     * Output CSV Form
     */
    public function exportForm()
    {
        $this->getState()->template = 'auditlog-form-export';
        $this->getState()->setData([
            'help' => $this->getHelp()->link('AuditLog', 'Export')
        ]);
    }

    /**
     * Outputs a CSV of audit trail messages
     */
    public function export()
    {
        // We are expecting some parameters
        $filterFromDt = $this->getSanitizer()->getDate('filterFromDt');
        $filterToDt = $this->getSanitizer()->getDate('filterToDt');

        if ($filterFromDt == null || $filterToDt == null)
            throw new \InvalidArgumentException(__('Please provide a from/to date.'));

        $search = [
            ['fromTimeStamp', $filterFromDt->setTime(0, 0, 0)->format('U')],
            ['toTimeStamp', $filterToDt->setTime(0, 0, 0)->format('U')]
        ];

        // Build the search string
        $search = implode(' ', array_map(function ($element) {
            return implode('|', $element);
        }, $search));

        $rows = $this->getFactoryService()->get('AuditLogFactory')->query('logId', ['search' => $search]);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Date', 'User', 'Entity', 'Message', 'Object']);

        // Do some post processing
        foreach ($rows as $row) {
            /* @var \Xibo\Entity\AuditLog $row */
            fputcsv($out, [$row->logId, $this->getDate()->getLocalDate($row->logDate), $row->userName, $row->entity, $row->message, $row->objectAfter]);
        }

        fclose($out);

        // We want to output a load of stuff to the browser as a text file.
        $app = $this->getContainer();
        $app->response()->header('Content-Type', 'text/csv');
        $app->response()->header('Content-Disposition', 'attachment; filename="audittrail.csv"');
        $app->response()->header('Content-Transfer-Encoding', 'binary"');
        $app->response()->header('Accept-Ranges', 'bytes');
        $this->setNoOutput(true);
    }
}
