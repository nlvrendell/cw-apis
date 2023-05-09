<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ContactController extends Controller
{
    public $cw_base_api;

    public function __construct()
    {
        $this->cw_base_api = env('CW_BASE_API_URL');
    }

    public function index(Request $request)
    {
        // return 'api is here';
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['error' => 'Unauthorized bearer token'], 401);
        }

        if (! $request->input('domain')) {
            return response()->json(['error' => 'Domain is required'], 401);
        }

        if (! $request->input('type')) {
            return response()->json(['error' => 'Type is required'], 401);
        }

        $groupings = $request->input('type') == 'site' ? $this->sites($bearerToken, $request->domain) : $this->departments($bearerToken, $request->domain);
        $groups = $groupings->json();

        // Adding others and shared
        if ((array_search('Others', $groups)) == false || (array_search('others', $groups)) == false) {
            array_push($groups, 'Others');
        }

        // Adding others
        if ((array_search('Shared', $groups)) == false || (array_search('shared', $groups)) == false) {
            array_push($groups, 'Shared');
        }

        // return $groupings->json();
        $contacts = $this->contacts($bearerToken, $request->domain);

        // return $hold = $contacts->json();

        // foreach ($hold as $key => $contact) {
        //     // $category = ($request->input('type') == 'department') ? 'group' : 'site';

        //     if (array_key_exists('group', $contact)) {
        //         echo $contact['group'];
        //     } else {
        //         echo 'wala';
        //     }
        //     // $category = 'group';

        //     // if ((array_search($category, $contact)) !== false) {
        //     //     echo $contact['group'];
        //     // } else {
        //     //     echo 'Others';
        //     // }
        // }

        // return 'end';

        // initialized xml object
        $xmlobj = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><AddressBook></AddressBook>');

        // adding group data
        $xmlobj = $this->groupToXML($xmlobj, $groups);
        $xmlobj = $this->contactToXML($xmlobj, $contacts->json(), $groups, $request->input('type'));

        return $xmlobj->asXML();
    }

    public function departments($token, $domain)
    {
        return Http::withToken($token)
            ->post($this->cw_base_api.'?object=department&action=list&format=json&domain='.$domain.'');
    }

    public function sites($token, $domain)
    {
        return Http::withToken($token)
            ->post($this->cw_base_api.'?format=json&object=site&action=list&domain='.$domain.'');
    }

    public function contacts($token, $domain)
    {
        return Http::withToken($token)
            ->post($this->cw_base_api.'?format=json&object=contact&action=read&domain='.$domain.'&includeDomain=yes&user=1003');
    }

    public function groupToXML($xmlobj, $groups)
    {

        if (($key = array_search('n/a', $groups)) !== false) {
            unset($groups[$key]);
        }

        foreach ($groups as $key => $data) {
            $group = $xmlobj->addChild('pbgroup');
            $group->addChild('id', htmlspecialchars($key));
            $group->addChild('name', htmlspecialchars($data));
        }

        // $group->addChild('id', htmlspecialchars($groups));
        // $group->addChild('name', htmlspecialchars($data));

        return $xmlobj;
    }

    public function contactToXML($xmlobj, $contacts, $groups, $type)
    {
        foreach ($contacts as $key => $data) {
            $contact = $xmlobj->addChild('Contact');
            $contact->addChild('id', htmlspecialchars($key));
            $contact->addChild('FirstName', htmlspecialchars($data['first_name']));
            $contact->addChild('LastName', htmlspecialchars($data['last_name']));
            $contact->addChild('Frequent', htmlspecialchars(0));

            $phone = $contact->addChild('Phone');
            $phone->addAttribute('type', 'Work');
            $phone->addChild('phonenumber', htmlspecialchars(100));
            $phone->addChild('accountindex', htmlspecialchars(0));

            $contact->addChild('Group', htmlspecialchars($this->identifyGroup($data, $groups, $type))); // there should be a group identification here
            $contact->addChild('Primary', htmlspecialchars(0)); // there should be a group identification here
        }

        return $xmlobj;
    }

    public function identifyGroup($contact, $groups, $type)
    {
        // if the contact person is shared return the shared id array_search('n/a', $groups))
        if ((array_search('Shared', $contact)) !== false) {
            return array_search('Shared', $groups);
        }

        $category = ($type == 'department') ? 'group' : 'site';

        // // if the contact person has data on category return the group id
        if (array_key_exists($category, $contact) && $contact[$category] !== '') {
            return array_search($contact[$category], $groups);
        }

        // if the contact person dont have data on category return the others id
        return array_search('Others', $groups);
    }
}
