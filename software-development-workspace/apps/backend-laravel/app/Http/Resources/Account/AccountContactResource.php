<?php

namespace App\Http\Resources\Account;

use App\Support\Audit\PiiMasker;
use App\Support\Authorization\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * Reachable details — a real person's email and phone at a client —
         * are classified behind `accounts.view_contacts`. Masked, never
         * blank, per the commercials_visible house style: an honest "hidden"
         * the UI can explain, where an empty field would read as missing
         * data. Names and designations stay open — they are who the contact
         * is, not how to reach them.
         */
        $showsContacts = $request->user()?->hasPermission(Permission::ACCOUNTS_VIEW_CONTACTS) ?? false;

        return [
            'id'              => $this->id,
            'category'        => $this->category,
            'title'           => $this->title,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'full_name'       => $this->full_name,
            'designation'     => $this->designation,
            'department'      => $this->department,
            'email'           => $showsContacts || $this->email === null ? $this->email : PiiMasker::maskEmail($this->email),
            'phone'           => $showsContacts || $this->phone === null ? $this->phone : PiiMasker::maskTail($this->phone),
            'alternate_phone' => $showsContacts || $this->alternate_phone === null ? $this->alternate_phone : PiiMasker::maskTail($this->alternate_phone),
            'contacts_visible' => $showsContacts,
            'is_primary'      => $this->is_primary,
            'is_active'       => $this->is_active,
        ];
    }
}
