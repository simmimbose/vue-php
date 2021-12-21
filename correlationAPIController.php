<?php

namespace App\Http\Controllers\API;

use App\CorrelationEntry;
use App\CorrelationLog;
use App\CorrelationRule;
use App\CorrelationType;
use App\Http\Controllers\Controller;
use App\User;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\CorrelationGroup;

class CorrelationController extends Controller
{
    public function __construct()
    {
    }

    public function create()
    {
        $actor = Auth::user();

        // Temporary to make sure correlation v1 and v2 works
        $typeUid = request('type_uid', request('typeUid'));
        $objects = request('objects', request('objects'));
        $ruleUids = request('rule_uids', request('ruleUids'));

        // Use this when v1 is no longer used
        // $typeUid = request('typeUid');
        // $objects = request('objects');
        // $ruleUids = request('ruleUids');

        $alias = md5(serialize($objects).serialize($ruleUids));

        // Get existing entry if exists
        $entry = CorrelationEntry::where('alias', $alias)->first();

        // Create new entry
        if (empty($entry)) {
            $entry = new CorrelationEntry();
            $entry->alias = $alias;
            $entry->type_uid = $typeUid;
            $entry->objects = $objects;
            $entry->rule_uids = $ruleUids;
            $entry->viewed_by = $actor->uid;
            $entry->created_by = $actor->uid;
            $entry->save();

        // Update existing entry
        } else {
            $entry->viewed_by = $actor->uid;
            $entry->save();
        }

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function correlate()
    {
        $ruleUid = request()->input('ruleUid');
        $objects = request()->input('objects');

        $correlationRule = CorrelationRule::where('uid', $ruleUid)->first();

        if (empty($correlationRule)) {
            return response()->json([
                'errors' => [
                    'title' => 'Correlation rule not found.',
                ],
            ], 400);
        }

        $correlationQuery = DB::raw(str_replace(
                '[<set>]',
                implode(',', array_fill(0, count($objects), '?')),
                $correlationRule->correlationQuery
            ));

        $results = DB::select($correlationQuery, $objects);

        $total = count($objects);

        $datamap = [];
        $dataset = [];
        $valuemap = [];
        $valueset = [];
        $objectset = [];
        $unused = [];

        if (!empty($results)) {
            foreach ($results as $result) {
                $value = $result->CorValue;
                $object = $result->CorObject;

                if (is_null($value)) {
                    $value = '(Unknown)';
                }

                // datamap
                if (isset($datamap[$value])) {
                    $data = $datamap[$value];
                } else {
                    $data = (object) [
                        'percent'  => '0%',
                        'fraction' => '0/0',
                        'count'    => 0,
                        'objects'  => [],
                    ];

                    $datamap[$value] = $data;
                }

                if (!isset($data->objects[$object])) {
                    $data->objects[$object] = true;
                    ++$data->count;
                }

                // valuemap
                if (!isset($valuemap[$object])) {
                    $valuemap[$object] = [];
                    $objectset[strtoupper($object)] = true; // This is used to compare against unused objects
                }

                $valuemap[$object][$value] = true;
            }

            // Convert datamap into dataset
            foreach ($datamap as $value => $data) {
                // Add value property
                $data->value = $value;

                // Calculate fraction & percentage on dataset
                $count = $data->count;
                $data->fraction = "$count/$total";
                $data->percent = round($count / $total * 100, 2).'%';

                // Convert object list into an array
                $data->objects = array_keys($data->objects);

                $dataset[] = $data;
            }

            // Sort dataset descendingly
            $dataset = collect($dataset)->sortByDesc('count')->values()->toArray();

            // Convert valuemap into valueset
            foreach ($valuemap as $value => $map) {
                $valueset[$value] = array_keys($map);
            }

            // Find unused objects
            foreach ($objects as $object) {
                if (!isset($objectset[strtoupper($object)])) {
                    $unused[] = $object;
                }
            }
        }

        // Log correlation
        $user = Auth::user();

        CorrelationLog::create([
            'userUid' => $user->uid,
            'ruleUid' => $ruleUid,
            'objects' => implode("\n", $objects),
        ]);

        return response()->json([
            'data' => [
                'dataset'   => $dataset,
                'valueset'  => $valueset,
                'unused'    => $unused,
                'total'     => $total,
            ],
        ]);
    }

    public function objects()
    {
        $ruleUid = request()->input('ruleUid');
        $objects = request()->input('objects');
        $value = request()->input('value');

        $correlationRule = CorrelationRule::where('uid', $ruleUid)->first();

        if (empty($correlationRule)) {
            return response()->json([
                'errors' => [
                    'title' => 'Correlation rule not found.',
                ],
            ], 400);
        }

        $objectQuery = $correlationRule->objectQuery;

        $objectQuery = str_replace(
            '[<set>]',
            implode(',', array_fill(0, count($objects), '?')),
            $objectQuery
        );

        if ($value == '(Unknown)') {
            $objectQuery = str_replace(
                '[<value>]',
                ' IS NULL',
                $objectQuery
            );
        } else {
            $objectQuery = str_replace(
                '[<value>]',
                ' = ?',
                $objectQuery
            );
        }

        $objectQuery = DB::raw($objectQuery);

        if ($value == '(Unknown)') {
            $params = $objects;
        } else {
            $params = array_merge($objects, [$value]);
        }

        $results = DB::select(
                $objectQuery,
                $params
            );

        return response()->json([
            'data' => $results,
        ]);
    }

    // To get all available correlation types
    public function types()
    {
        $correlationTypes = CorrelationType::orderBy('order')->get();

        return response()->json([
            'data' => $correlationTypes,
        ]);
    }

    // To get all available correlation groups & rules
    public function type()
    {
        $typeUid = 1;

        $correlationType = CorrelationType::with([
                'groups' => function ($query) {
                    $query->orderBy('order');
                },
                'groups.rules' => function ($query) {
                    $query->orderBy('order');
                },
            ])
            ->where('uid', $typeUid)
            ->orderBy('order')
            ->first();

        return response()->json([
            'data' => $correlationType,
        ]);
    }

    // To get all available correlation rules
    public function rules()
    {
        $rules = CorrelationRule::all();

        return response()->json([
            'data' => $rules,
        ]);
    }

    // To get all available correlation groups
    public function groups()
    {
        $groups = CorrelationGroup::orderBy('order', 'ASC')->get();
        //$groups = CorrelationGroup::all()->keyBy('uid');

        return response()->json([
            'data' => $groups,
        ]);
    }


    public function getDetails()
    {
        $alias = request('alias');
        if (!is_null($alias)) {
            $entry = CorrelationEntry::where('alias', $alias)->first();
        }

        $data = [
            'uid'       => (int) $entry->uid,
            'alias'     => $entry->alias,
            'typeUid'   => (int) $entry->type_uid,
            'objects'   => $entry->objects,
            'ruleUids' => $entry->rule_uids,
            'createdAt' => $entry->created_at,
            'createdBy' => (int) $entry->created_by
        ];

        return response()->json([
            'data' => $data
        ]);
    }
}
