<?php

namespace App\Support;

use Illuminate\Http\Request;

class DeviceDetector
{
    /**
     * @return array{
     *     type: 'mobile'|'tablet'|'desktop',
     *     view: 'mobile'|'web',
     *     is_mobile: bool,
     *     is_tablet: bool,
     *     is_desktop: bool
     * }
     */
    public function fromRequest(Request $request): array
    {
        $userAgent = strtolower($request->userAgent() ?? '');
        $isTablet = $this->isTablet($userAgent);
        $isMobile = ! $isTablet && $this->isMobile($userAgent);
        $type = $isTablet ? 'tablet' : ($isMobile ? 'mobile' : 'desktop');

        return [
            'type' => $type,
            'view' => $type === 'desktop' ? 'web' : 'mobile',
            'is_mobile' => $isMobile,
            'is_tablet' => $isTablet,
            'is_desktop' => $type === 'desktop',
        ];
    }

    private function isMobile(string $userAgent): bool
    {
        return (bool) preg_match(
            '/android.*mobile|iphone|ipod|blackberry|iemobile|opera mini|mobile safari|windows phone/i',
            $userAgent,
        );
    }

    private function isTablet(string $userAgent): bool
    {
        return (bool) preg_match(
            '/ipad|tablet|kindle|silk|playbook|android(?!.*mobile)/i',
            $userAgent,
        );
    }
}
