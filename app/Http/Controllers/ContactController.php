<?php

namespace App\Http\Controllers;

use App\Models\Contact;


class ContactController extends Controller
{
    public function index()
    {
        $myNumbers = auth()->user()->numbers()->pluck('id');

        $contacts = Contact::whereIn('number_id', $myNumbers)
            ->with(['number', 'contactNumber.user'])
            ->latest()
            ->paginate(20);

        return view('contacts.index', compact('contacts'));
    }

    public function accept(Contact $contact)
    {
        $myNumbers = auth()->user()->numbers()->pluck('id');
        abort_unless($myNumbers->contains($contact->number_id), 403);

        $contact->update(['status' => 'accepted']);

        // Also accept the reverse direction if it exists
        Contact::where('number_id', $contact->contact_number_id)
            ->where('contact_number_id', $contact->number_id)
            ->update(['status' => 'accepted']);

        return back()->with('success', 'Contact accepted.');
    }

    public function destroy(Contact $contact)
    {
        $myNumbers = auth()->user()->numbers()->pluck('id');
        abort_unless($myNumbers->contains($contact->number_id), 403);

        // Remove both directions
        Contact::where(function ($q) use ($contact) {
            $q->where('number_id', $contact->number_id)
              ->where('contact_number_id', $contact->contact_number_id);
        })->orWhere(function ($q) use ($contact) {
            $q->where('number_id', $contact->contact_number_id)
              ->where('contact_number_id', $contact->number_id);
        })->delete();

        return back()->with('success', 'Contact removed.');
    }
}