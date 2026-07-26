<?php

return [
    /*
    | Master switch for the custom fields feature. When false, no custom field
    | inputs/columns/infolist entries render on resources and the Custom Fields
    | admin resource is hidden. Stored values are left untouched.
    */
    'enabled' => env('CUSTOM_FIELDS_ENABLED', true),
];
