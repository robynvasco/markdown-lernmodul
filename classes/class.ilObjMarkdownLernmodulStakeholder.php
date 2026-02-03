<?php
declare(strict_types=1);

use ILIAS\ResourceStorage\Stakeholder\AbstractResourceStakeholder;

/**
 * Stakeholder for MarkdownLernmodul temporary file uploads
 */
class ilObjMarkdownLernmodulStakeholder extends AbstractResourceStakeholder
{
    public function getId(): string
    {
        return 'mdquiz_upload';
    }
    
    public function getOwnerOfNewResources(): int
    {
        global $DIC;
        return $DIC->user()->getId();
    }
    
    public function resourceHasBeenDeleted(
        \ILIAS\ResourceStorage\Identification\ResourceIdentification $identification
    ): bool {
        // Temporary uploads can be deleted
        return true;
    }
    
    public function getLocationURIForResourceUsage(
        \ILIAS\ResourceStorage\Identification\ResourceIdentification $identification
    ): ?string {
        return null;
    }
}
