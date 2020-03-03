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
namespace Xibo\Controller;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Slim\Views\Twig;
use Xibo\Entity\Page;
use Xibo\Entity\Permission;
use Xibo\Entity\User;
use Xibo\Support\Exception\AccessDeniedException;
use Xibo\Factory\PageFactory;
use Xibo\Factory\PermissionFactory;
use Xibo\Factory\UserFactory;
use Xibo\Factory\UserGroupFactory;
use Xibo\Helper\ByteFormatter;
use Xibo\Helper\SanitizerService;
use Xibo\Service\ConfigServiceInterface;
use Xibo\Service\DateServiceInterface;
use Xibo\Service\LogServiceInterface;

/**
 * Class UserGroup
 * @package Xibo\Controller
 */
class UserGroup extends Base
{
    /**
     * @var UserGroupFactory
     */
    private $userGroupFactory;

    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @var PermissionFactory
     */
    private $permissionFactory;

    /**
     * @var UserFactory
     */
    private $userFactory;

    /**
     * Set common dependencies.
     * @param LogServiceInterface $log
     * @param SanitizerService $sanitizerService
     * @param \Xibo\Helper\ApplicationState $state
     * @param \Xibo\Entity\User $user
     * @param \Xibo\Service\HelpServiceInterface $help
     * @param DateServiceInterface $date
     * @param ConfigServiceInterface $config
     * @param UserGroupFactory $userGroupFactory
     * @param PageFactory $pageFactory
     * @param PermissionFactory $permissionFactory
     * @param UserFactory $userFactory
     * @param Twig $view
     */
    public function __construct($log, $sanitizerService, $state, $user, $help, $date, $config, $userGroupFactory, $pageFactory, $permissionFactory, $userFactory, Twig $view)
    {
        $this->setCommonDependencies($log, $sanitizerService, $state, $user, $help, $date, $config, $view);

        $this->userGroupFactory = $userGroupFactory;
        $this->pageFactory = $pageFactory;
        $this->permissionFactory = $permissionFactory;
        $this->userFactory = $userFactory;
    }

    /**
     * Display page logic
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     */
    function displayPage(Request $request, Response $response)
    {
        $this->getState()->template = 'usergroup-page';

        return $this->render($request, $response);
    }

    /**
     * Group Grid
     * @SWG\Get(
     *  path="/group",
     *  operationId="userGroupSearch",
     *  tags={"usergroup"},
     *  summary="UserGroup Search",
     *  description="Search User Groups",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="query",
     *      description="Filter by UserGroup Id",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="userGroup",
     *      in="query",
     *      description="Filter by UserGroup Name",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/UserGroup")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     */
    function grid(Request $request, Response $response)
    {
        $sanitizedQueryParams = $this->getSanitizer($request->getQueryParams());
        $filterBy = [
            'groupId' => $sanitizedQueryParams->getInt('userGroupId'),
            'group' => $sanitizedQueryParams->getString('userGroup'),
            'isUserSpecific' => 0
        ];

        $groups = $this->userGroupFactory->query($this->gridRenderSort($request), $this->gridRenderFilter($filterBy, $request));
        $this->getLog()->debug('GROUP GRID RESULTS ' . json_encode($groups));
        foreach ($groups as $group) {
            /* @var \Xibo\Entity\UserGroup $group */

            $group->libraryQuotaFormatted = ByteFormatter::format($group->libraryQuota * 1024);

            if ($this->isApi($request))
                continue;

            // we only want to show certain buttons, depending on the user logged in
            if ($this->isEditable($group, $request)) {
                // Edit
                $group->buttons[] = array(
                    'id' => 'usergroup_button_edit',
                    'url' => $this->urlFor($request,'group.edit.form', ['id' => $group->groupId]),
                    'text' => __('Edit')
                );

                if ($this->getUser()->isSuperAdmin()) {
                    // Delete
                    $group->buttons[] = array(
                        'id' => 'usergroup_button_delete',
                        'url' => $this->urlFor($request,'group.delete.form', ['id' => $group->groupId]),
                        'text' => __('Delete')
                    );

                    $group->buttons[] = ['divider' => true];

                    // Copy
                    $group->buttons[] = array(
                        'id' => 'usergroup_button_copy',
                        'url' => $this->urlFor($request,'group.copy.form', ['id' => $group->groupId]),
                        'text' => __('Copy')
                    );

                    $group->buttons[] = ['divider' => true];
                }

                // Members
                $group->buttons[] = array(
                    'id' => 'usergroup_button_members',
                    'url' => $this->urlFor($request,'group.members.form', ['id' => $group->groupId]),
                    'text' => __('Members')
                );

                if ($this->getUser()->isSuperAdmin()) {
                    // Page Security
                    $group->buttons[] = array(
                        'id' => 'usergroup_button_page_security',
                        'url' => $this->urlFor($request,'group.acl.form', ['id' => $group->groupId]),
                        'text' => __('Page Security')
                    );
                }
            }
        }

        $this->getState()->template = 'grid';
        $this->getState()->recordsTotal = $this->userGroupFactory->countLast();
        $this->getState()->setData($groups);

        return $this->render($request, $response);
    }

    /**
     * Form to Add a Group
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     */
    function addForm(Request $request, Response $response)
    {
        $this->getState()->template = 'usergroup-form-add';
        $this->getState()->setData([
            'help' => [
                'add' => $this->getHelp()->link('UserGroup', 'Add')
            ]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Form to Edit a Group
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function editForm(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        $this->getState()->template = 'usergroup-form-edit';
        $this->getState()->setData([
            'group' => $group,
            'help' => [
                'add' => $this->getHelp()->link('UserGroup', 'Edit')
            ]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Shows the Delete Group Form
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function deleteForm(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        $this->getState()->template = 'usergroup-form-delete';
        $this->getState()->setData([
            'group' => $group,
            'help' => [
                'delete' => $this->getHelp()->link('UserGroup', 'Delete')
            ]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Add User Group
     * @SWG\Post(
     *  path="/group",
     *  operationId="userGroupAdd",
     *  tags={"usergroup"},
     *  summary="UserGroup Add",
     *  description="Add User Group",
     *  @SWG\Parameter(
     *      name="group",
     *      in="formData",
     *      description="Name of the User Group",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="libraryQuota",
     *      in="formData",
     *      description="The quota that should be applied (KiB). Provide 0 for no quota",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="isSystemNotification",
     *      in="formData",
     *      description="Flag (0, 1), should members of this Group receive system notifications?",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="isDisplayNotification",
     *      in="formData",
     *      description="Flag (0, 1), should members of this Group receive Display notifications for Displays they have permissions to see",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/UserGroup")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\InvalidArgumentException
     */
    function add(Request $request, Response $response)
    {
        $sanitizedParams = $this->getSanitizer($request->getParams());

        // Check permissions
        if (!$this->getUser()->isSuperAdmin()) {
            throw new AccessDeniedException();
        }

        // Build a user entity and save it
        $group = $this->userGroupFactory->createEmpty();
        $group->group = $sanitizedParams->getString('group');
        $group->libraryQuota = $sanitizedParams->getInt('libraryQuota');

        if ($this->getUser()->userTypeId == 1) {
            $group->isSystemNotification = $sanitizedParams->getCheckbox('isSystemNotification');
            $group->isDisplayNotification = $sanitizedParams->getCheckbox('isDisplayNotification');
        }

        // Save
        $group->save();

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Added %s'), $group->group),
            'id' => $group->groupId,
            'data' => $group
        ]);

        return $this->render($request, $response);
    }

    /**
     * Edit User Group
     * @SWG\Put(
     *  path="/group/{userGroupId}",
     *  operationId="userGroupEdit",
     *  tags={"usergroup"},
     *  summary="UserGroup Edit",
     *  description="Edit User Group",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="path",
     *      description="ID of the User Group",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="group",
     *      in="formData",
     *      description="Name of the User Group",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="libraryQuota",
     *      in="formData",
     *      description="The quota that should be applied (KiB). Provide 0 for no quota",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="isSystemNotification",
     *      in="formData",
     *      description="Flag (0, 1), should members of this Group receive system notifications?",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="isDisplayNotification",
     *      in="formData",
     *      description="Flag (0, 1), should members of this Group receive Display notifications for Displays they have permissions to see",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/UserGroup")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\InvalidArgumentException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function edit(Request $request, Response $response, $id)
    {
        // Check permissions
        if (!$this->getUser()->isSuperAdmin() && !$this->getUser()->isGroupAdmin()) {
            throw new AccessDeniedException();
        }

        $sanitizedParams = $this->getSanitizer($request->getParams());

        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group)) {
            throw new AccessDeniedException();
        }

        $group->load();

        $group->group = $sanitizedParams->getString('group');
        $group->libraryQuota = $sanitizedParams->getInt('libraryQuota');

        if ($this->getUser()->userTypeId == 1) {
            $group->isSystemNotification = $sanitizedParams->getCheckbox('isSystemNotification');
            $group->isDisplayNotification = $sanitizedParams->getCheckbox('isDisplayNotification');
        }

        // Save
        $group->save();

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Edited %s'), $group->group),
            'id' => $group->groupId,
            'data' => $group
        ]);

        return $this->render($request, $response);
    }

    /**
     * Delete User Group
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     * @SWG\Delete(
     *  path="/group/{userGroupId}",
     *  operationId="userGroupDelete",
     *  tags={"usergroup"},
     *  summary="Delete User Group",
     *  description="Delete User Group",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="path",
     *      description="The user Group ID to Delete",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=204,
     *      description="successful operation"
     *  )
     * )
     */
    function delete(Request $request, Response $response, $id)
    {
        // Check permissions
        if (!$this->getUser()->isSuperAdmin()) {
            throw new AccessDeniedException();
        }

        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group)) {
            throw new AccessDeniedException();
        }

        $group->delete();

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Deleted %s'), $group->group),
            'id' => $group->groupId
        ]);

        return $this->render($request, $response);
    }

    /**
     * ACL Form for the provided GroupId
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function aclForm(Request $request, Response $response, $id)
    {
        // Check permissions to this function
        if (!$this->getUser()->isSuperAdmin()) {
            throw new AccessDeniedException();
        }

        // Use the factory to get all the entities
        $entities = $this->pageFactory->query();

        // Load the Group we are working on
        // Get the object
        if ($id == 0) {
            throw new \InvalidArgumentException(__('ACL form requested without a User Group'));
        }

        $group = $this->userGroupFactory->getById($id);

        // Get all permissions for this user and this object
        $permissions = $this->permissionFactory->getByGroupId('Page', $id);

        $checkboxes = [];

        foreach ($entities as $entity) {
            /* @var Page $entity */
            // Check to see if this entity is set or not
            $entityId = $entity->getId();
            $viewChecked = 0;

            foreach ($permissions as $permission) {
                /* @var Permission $permission */
                if ($permission->objectId == $entityId && $permission->view == 1) {
                    $viewChecked = 1;
                    break;
                }
            }

            // Store this checkbox
            $checkbox = [
                'id' => $entityId,
                'name' => $entity->title,
                'value_view' => $entityId . '_view',
                'value_view_checked' => (($viewChecked == 1) ? 'checked' : '')
            ];

            $checkboxes[] = $checkbox;
        }

        $data = [
            'title' => sprintf(__('ACL for %s'), $group->group),
            'groupId' => $id,
            'group' => $group->group,
            'permissions' => $checkboxes,
            'help' => $this->getHelp()->link('User', 'Acl')
        ];

        $this->getState()->template = 'usergroup-form-acl';
        $this->getState()->setData($data);

        return $this->render($request, $response);
    }

    /**
     * ACL update
     * @param Request $request
     * @param Response $response
     * @param int $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function acl(Request $request, Response $response, $id)
    {
        // Check permissions to this function
        if (!$this->getUser()->isSuperAdmin()) {
            throw new AccessDeniedException();
        }

        // Load the Group we are working on
        // Get the object
        if ($id == 0) {
            throw new \InvalidArgumentException(__('ACL form requested without a User Group'));
        }

        $group = $this->userGroupFactory->getById($id);

        // Use the factory to get all the entities
        $entities = $this->pageFactory->query();

        // Get all permissions for this user and this object
        $permissions = $this->permissionFactory->getByGroupId('Page', $id);
        $objectIds = $request->getParam('objectId', null);

        if (!is_array($objectIds)) {
            $objectIds = [];
        }

        $newAcl = [];
        array_map(function ($string) use (&$newAcl) {
            $array = explode('_', $string);
            return $newAcl[$array[0]][$array[1]] = 1;
        }, $objectIds);

        $this->getLog()->debug(var_export($newAcl, true));

        foreach ($entities as $page) {
            /* @var Page $page */
            // Check to see if this entity is set or not
            $objectId = $page->getId();
            $permission = null;
            $view = (array_key_exists($objectId, $newAcl));

            // Is the permission currently assigned?
            foreach ($permissions as $row) {
                /* @var \Xibo\Entity\Permission $row */
                if ($row->objectId == $objectId) {
                    $permission = $row;
                    break;
                }
            }

            if ($permission == null) {
                if ($view) {
                    // Not currently assigned and needs to be
                    $permission = $this->permissionFactory->create($id, get_class($page), $objectId, 1, 0, 0);
                    $permission->save();
                }
            }
            else {
                $this->getLog()->debug('Permission Exists for %s, and has been set to %d.', $page->getName(), $view);
                // Currently assigned
                if ($view) {
                    $permission->view = 1;
                    $permission->save();
                }
                else {
                    $permission->delete();
                }
            }
        }

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('ACL set for %s'), $group->group),
            'id' => $group->groupId
        ]);

        return $this->render($request, $response);
    }

    /**
     * Shows the Members of a Group
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function membersForm(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        // Users in group
        $usersAssigned = $this->userFactory->query(null, ['groupIds' => [$id]]);

        // Users not in group
        $allUsers = $this->userFactory->query();

        // The available users are all users except users already in assigned users
        $checkboxes = [];

        foreach ($allUsers as $user) {
            /* @var User $user */
            // Check to see if it exists in $usersAssigned
            $exists = false;
            foreach ($usersAssigned as $userAssigned) {
                /* @var User $userAssigned */
                if ($userAssigned->userId == $user->userId) {
                    $exists = true;
                    break;
                }
            }

            // Store this checkbox
            $checkbox = array(
                'id' => $user->userId,
                'name' => $user->userName,
                'value_checked' => (($exists) ? 'checked' : '')
            );

            $checkboxes[] = $checkbox;
        }

        $this->getState()->template = 'usergroup-form-members';
        $this->getState()->setData([
            'group' => $group,
            'checkboxes' => $checkboxes,
            'help' =>  $this->getHelp()->link('UserGroup', 'Members')
        ]);

        return $this->render($request, $response);
    }

    /**
     * Assign User to the User Group
     * @SWG\Post(
     *  path="/group/members/assign/{userGroupId}",
     *  operationId="userGroupAssign",
     *  tags={"usergroup"},
     *  summary="Assign User to User Group",
     *  description="Assign User to User Group",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="path",
     *      description="ID of the user group to which assign the user",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="userId",
     *      in="formData",
     *      description="Array of userIDs to assign",
     *      type="array",
     *      required=true,
     *      @SWG\Items(type="integer")
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/UserGroup")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\InvalidArgumentException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function assignUser(Request $request, Response $response, $id)
    {
        $this->getLog()->debug(sprintf('Assign User for groupId %d', $id));
        $sanitizedPaarams = $this->getSanitizer($request->getParams());

        $group = $this->userGroupFactory->getById($id);
        $group->load();

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        $users = $sanitizedPaarams->getIntArray('userId');

        foreach ($users as $userId) {

            $this->getLog()->debug(sprintf('Assign User %d for groupId %d', $userId, $id));

            $user = $this->userFactory->getById($userId);

            if (!$this->getUser()->checkViewable($user)) {
                throw new AccessDeniedException(__('Access Denied to User'));
            }

            $group->assignUser($user);
            $group->save(['validate' => false]);
        }

        // Check to see if unassign has been provided.
        $users = $sanitizedPaarams->getIntArray('unassignUserId');

        foreach ($users as $userId) {

            $this->getLog()->debug(sprintf('Unassign User %d for groupId %d', $userId, $id));

            $user = $this->userFactory->getById($userId);

            if (!$this->getUser()->checkViewable($user)) {
                throw new AccessDeniedException(__('Access Denied to User'));
            }

            $group->unassignUser($user);
            $group->save(['validate' => false]);
        }


        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Membership set for %s'), $group->group),
            'id' => $group->groupId
        ]);

        return $this->render($request, $response);
    }

    /**
     * Unassign User to the User Group
     * @SWG\Post(
     *  path="/group/members/unassign/{userGroupId}",
     *  operationId="userGroupUnassign",
     *  tags={"usergroup"},
     *  summary="Unassign User from User Group",
     *  description="Unassign User from User Group",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="path",
     *      description="ID of the user group from which to unassign the user",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="userId",
     *      in="formData",
     *      description="Array of userIDs to unassign",
     *      type="array",
     *      required=true,
     *      @SWG\Items(type="integer")
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/UserGroup")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\InvalidArgumentException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function unassignUser(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);
        $sanitizedParams = $this->getSanitizer($request->getParams());

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        $users = $sanitizedParams->getIntArray('userId');

        foreach ($users as $userId) {
            $group->unassignUser($this->userFactory->getById($userId));
        }

        $group->save(['validate' => false]);

        // Return
        $this->getState()->hydrate([
            'message' => sprintf(__('Membership set for %s'), $group->group),
            'id' => $group->groupId
        ]);

        return $this->render($request, $response);
    }

    /**
     * Form to Copy Group
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function copyForm(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);

        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        $this->getState()->template = 'usergroup-form-copy';
        $this->getState()->setData([
            'group' => $group
        ]);

        return $this->render($request, $response);
    }

    /**
     * @SWG\Post(
     *  path="/group/{userGroupId}/copy",
     *  operationId="userGroupCopy",
     *  tags={"usergroup"},
     *  summary="Copy User Group",
     *  description="Copy an user group, optionally copying the group members",
     *  @SWG\Parameter(
     *      name="userGroupId",
     *      in="path",
     *      description="The User Group ID to Copy",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="group",
     *      in="formData",
     *      description="The Group Name",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="copyMembers",
     *      in="formData",
     *      description="Flag indicating whether to copy group members",
     *      type="integer",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=201,
     *      description="successful operation",
     *      @SWG\Schema(ref="#/definitions/UserGroup"),
     *      @SWG\Header(
     *          header="Location",
     *          description="Location of the new record",
     *          type="string"
     *      )
     *  )
     * )
     *
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\DuplicateEntityException
     * @throws \Xibo\Support\Exception\GeneralException
     * @throws \Xibo\Support\Exception\InvalidArgumentException
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    public function copy(Request $request, Response $response, $id)
    {
        $group = $this->userGroupFactory->getById($id);
        $sanitizedParams = $this->getSanitizer($request->getParams());

        // Check we have permission to view this group
        if (!$this->isEditable($group, $request)) {
            throw new AccessDeniedException();
        }

        // Clone the group
        $group->load([
            'loadUsers' => ($sanitizedParams->getCheckbox('copyMembers') == 1)
        ]);
        $newGroup = clone $group;
        $newGroup->group = $sanitizedParams->getString('group');
        $newGroup->save();

        // Copy permissions
        foreach ($this->permissionFactory->getByGroupId('Page', $group->groupId) as $permission) {
            /* @var Permission $permission */
            $permission = clone $permission;
            $permission->groupId = $newGroup->groupId;
            $permission->save();
        }

        $this->getState()->hydrate([
            'httpStatus' => 201,
            'message' => sprintf(__('Copied %s'), $group->group),
            'id' => $newGroup->groupId,
            'data' => $newGroup
        ]);

        return $this->render($request, $response);
    }

    /**
     * @param \Xibo\Entity\UserGroup $group
     * @return bool
     */
    private function isEditable($group)
    {
        return $this->getUser()->isSuperAdmin()
            || ($this->getUser()->isGroupAdmin() && count(array_intersect($this->getUser()->groups, [$group])));
    }
}
