<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KnownKsefStatus: string
{
    case NotSent = 'not_sent';
    case Ok = 'ok';
    case DemoOk = 'demo_ok';
    case Processing = 'processing';
    case DemoProcessing = 'demo_processing';
    case StatusCheckError = 'status_check_error';
    case ServerError = 'server_error';
    case DemoServerError = 'demo_server_error';
    case SendError = 'send_error';
    case DemoSendError = 'demo_send_error';
    case Offline = 'offline';
    case OfflineError = 'offline_error';
    case Offline24 = 'offline24';
    case DuplicateError = 'duplicate_error';
    case Blocked403Error = 'blocked_403_error';
    case NotConnected = 'not_connected';
    case DemoNotConnected = 'demo_not_connected';
    case NotApplicable = 'not_applicable';
    case DemoNotApplicable = 'demo_not_applicable';
    case Rejected = 'rejected';
    case DemoRejected = 'demo_rejected';
}
