export type DeviceType = 'mobile' | 'tablet' | 'desktop';
export type DeviceView = 'mobile' | 'web';

export type DeviceContext = {
    type: DeviceType;
    view: DeviceView;
    is_mobile: boolean;
    is_tablet: boolean;
    is_desktop: boolean;
};
