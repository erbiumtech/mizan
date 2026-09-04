<?php

return [
    /*
    | The company as it appears on a document somebody outside the company reads —
    | a letterhead, and nothing else. The tenant's own row (`companies.name`) is what
    | the panel calls it; these are the details a bank, an embassy or the FBR needs,
    | and none of them existed anywhere before the income certificate needed them.
    |
    | Empty by default and per company through TenantSettings: there is no sensible
    | default for somebody else's NTN, and a letterhead half-filled with another
    | company's details would be worse than a blank one. Company Settings edits them.
    |
    | A certificate refuses to print while the ones it cannot do without are blank —
    | see App\Modules\Employees\Services\IncomeCertificate.
    */
    'legal_name' => env('COMPANY_LEGAL_NAME', ''),
    'address' => env('COMPANY_ADDRESS', ''),
    'phone' => env('COMPANY_PHONE', ''),
    'email' => env('COMPANY_EMAIL', ''),
    'website' => env('COMPANY_WEBSITE', ''),
    'registration_no' => env('COMPANY_REGISTRATION_NO', ''),
    'ntn' => env('COMPANY_NTN', ''),

    /*
    | Who signs. A title rather than a person is the default because the office
    | outlasts whoever holds it, and a certificate signed by a name nobody at the
    | company recognises is a certificate the bank calls about.
    */
    'signatory_name' => env('COMPANY_SIGNATORY_NAME', ''),
    'signatory_title' => env('COMPANY_SIGNATORY_TITLE', 'Chief Executive Officer'),

    /*
    | The prefix on a certificate's reference — ERB/HR/2026/EMP-014. Per company
    | because it is the company's own filing convention.
    */
    'document_ref_prefix' => env('COMPANY_DOCUMENT_REF_PREFIX', 'HR'),
];
