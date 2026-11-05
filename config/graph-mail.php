<?php

return [

    /*
    | The connection used when none is named.
    */
    'default' => env('GRAPH_MAIL_CONNECTION', 'default'),

    /*
    | One entry per mailbox. Needs an Entra app registration with the
    | Mail.Read (and Mail.Send, to send) application permissions.
    */
    'connections' => [
        'default' => [
            'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
            'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
            'mailbox' => env('MICROSOFT_GRAPH_MAILBOX'),
            'mail_folder' => env('MICROSOFT_GRAPH_MAIL_FOLDER', 'inbox'),
        ],
    ],

    /*
    | Pictures smaller than this are treated as signatures or logos, not documents.
    */
    'signature_picture_max_bytes' => 30_000,

    /*
    | A GIF (often animated, so larger) under this size is a signature or logo.
    */
    'gif_signature_max_bytes' => 200_000,

    /*
    | A regex matched against the attachment name: a small picture whose name
    | matches is kept as a document (e.g. '/awb|invoice|packing/i'). Null keeps none.
    */
    'keep_picture_name_pattern' => null,

];
