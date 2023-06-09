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

        $validator = $request->validate([
            'domain' => 'required|in:department,sites',
            'type' => 'required',
            'mac' => 'required',
        ]);

        // validate the mac address
        $isDeviceExist = $this->validateMac($bearerToken, $request->input('mac'));
        if(!$isDeviceExist){
            return response()->json(['error' => 'Mac is invalid'], 404);
        }

        $groupings = $request->input('type') == 'site' ? $this->sites($bearerToken, $request->domain) : $this->departments($bearerToken, $request->domain);
        $groups = $groupings->json();

        // Adding others
        if ((array_search('Others', $groups)) == false || (array_search('others', $groups)) == false) {
            array_push($groups, 'Others');
        }

        // Adding shared
        if ((array_search('Shared', $groups)) == false || (array_search('shared', $groups)) == false) {
            array_push($groups, 'Shared');
        }

        $contacts = $this->contacts($bearerToken, $request->domain);

        // initialized xml object
        $xmlobj = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><AddressBook></AddressBook>');

        // adding group data
        $xmlobj = $this->groupToXML($xmlobj, $groups);
        $xmlobj = $this->contactToXML($xmlobj, $contacts->json(), $groups, $request->input('type'));

        // return $xmlobj->asXML();

        return response($xmlobj->asXML(), 200)
                  ->header('Content-Type', 'text/plain');
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

            // if workphone exist use it, if not use the contact extensiont = user column
            $phoneNumber = array_key_exists('work_phone', $data) && $data['work_phone'] !== '' ? $data['work_phone'] : $data['user'];

            $phone->addChild('phonenumber', htmlspecialchars($phoneNumber));
            $phone->addChild('accountindex', htmlspecialchars(0));

            $contact->addChild('Group', htmlspecialchars($this->identifyGroup($data, $groups, $type))); // there should be a group identification here
            $contact->addChild('Primary', htmlspecialchars(0)); // there should be a group identification here
        }

        return $xmlobj;
    }

    public function identifyGroup($contact, $groups, $type)
    {
        // if the contact person is shared return the shared id 
        if ((array_search('Shared', $contact)) !== false) {
            return array_search('Shared', $groups);
        }

        $category = ($type == 'department') ? 'group' : 'site';

        // if the contact person has data on category return the group id
        if (array_key_exists($category, $contact) && $contact[$category] !== '') {
            return array_search($contact[$category], $groups);
        }

        // if the contact person dont have data on category used the others id
        return array_search('Others', $groups);
    }

    public function validateMac($token, $mac){
        $device = Http::withToken($token)
            ->post($this->cw_base_api.'?object=mac&action=read&format=json&mac='.$mac.'');
            
        $hold = $device->json()[0] ?? null; // 0 = first data
       
        // if auth user and pass exist then device exist
        return (isset($hold['auth_user']) && isset($hold['auth_pass'])) ? true : false;
    }
}
