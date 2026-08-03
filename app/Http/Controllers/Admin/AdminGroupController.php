<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\UsenetGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminGroupController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $groupname = $request->input('groupname') ?? '';
        $grouplist = UsenetGroup::getGroupsRange($groupname);
        $title = 'Group List';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist'));
    }

    /**
     * @throws \Exception
     */
    public function createBulk(Request $request): mixed
    {
        // set the current action
        $action = $request->input('action') ?? 'view';
        $groupmsglist = '';

        if ($action === 'submit') {
            $groupFilter = $request->input('groupfilter');

            if (is_string($groupFilter) && $groupFilter !== '') {
                $active = $request->has('active') ? $request->integer('active') : 1;
                $backfill = $request->has('backfill') ? $request->integer('backfill') : 1;
                $backfillTarget = max(1, $request->has('backfill_target') ? $request->integer('backfill_target') : 1);

                $groupmsglist = UsenetGroup::addBulk($groupFilter, $active, $backfill, $backfillTarget);
            }
        }

        $title = 'Bulk Add Newsgroups';

        return view('admin.groups.bulk', compact('title', 'groupmsglist'));
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        // Set the current action.
        $action = $request->input('action') ?? 'view';

        $group = [
            'id' => '',
            'name' => '',
            'description' => '',
            // 1, not 0: 0 is a real override that DISABLES the min-files delete
            // for the group (both predicates are `> 0` guarded), so the blank
            // add-group form used to switch a pipeline stage off by default.
            'minfilestoformrelease' => 1,
            'active' => 0,
            'backfill' => 0,
            'minsizetoformrelease' => 0,
            'first_record' => 0,
            'last_record' => 0,
            'backfill_target' => 0,
        ];

        switch ($action) {
            case 'submit':
                if (empty($request->input('id'))) {
                    // Add a new group.
                    $request->merge(['name' => UsenetGroup::isValidGroup($request->input('name'))]);
                    if ($request->input('name') !== false) {
                        UsenetGroup::addGroup($request->all());
                    }
                } else {
                    // Update an existing group.
                    UsenetGroup::updateGroup($request->all());
                }

                return redirect()->to('admin/group-list');

            case 'view':
            default:
                $title = 'Group Edit';
                if ($request->has('id')) {
                    $title = 'Newsgroup Edit';
                    $id = $request->input('id');
                    $group = UsenetGroup::getGroupByID($id);
                } else {
                    $title = 'Newsgroup Add';
                }
                break;
        }

        return view('admin.groups.edit', compact('title', 'group'));
    }

    /**
     * @throws \Exception
     */
    public function active(Request $request): mixed
    {
        $gname = '';
        if (! empty($request->input('groupname'))) {
            $gname = $request->input('groupname');
        }

        $groupname = ! empty($request->input('groupname')) ? $request->input('groupname') : '';
        $grouplist = UsenetGroup::getGroupsRange($gname, true);
        $title = 'Active Groups';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist'));
    }

    /**
     * @throws \Exception
     */
    public function inactive(Request $request): mixed
    {
        $gname = '';
        if (! empty($request->input('groupname'))) {
            $gname = $request->input('groupname');
        }

        $groupname = ! empty($request->input('groupname')) ? $request->input('groupname') : '';
        $grouplist = UsenetGroup::getGroupsRange($gname, false);
        $title = 'Inactive Groups';

        return view('admin.groups.index', compact('title', 'groupname', 'grouplist'));
    }
}
