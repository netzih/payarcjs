<?php

/**
 * The gateway may have received the request, but no reliable result was read.
 *
 * Callers must reconcile before retrying a charge.
 */
class CRM_Payarcjs_AmbiguousGatewayException extends CRM_Payarcjs_GatewayException {
}
