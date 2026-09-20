export type MenuIconName =
    | 'overview'
    | 'counter'
    | 'cashIn'
    | 'cashOut'
    | 'sendMoney'
    | 'receiveMoney'
    | 'transfer'
    | 'accounts'
    | 'exchange'
    | 'floats'
    | 'vault'
    | 'reconcile'
    | 'reports'
    | 'companies'
    | 'services'
    | 'fees'
    | 'users'
    | 'transactions'
    | 'settings';

export type MenuIconDefinition = {
    paths: string[];
    gradient: string;
};

export const menuIcons: Record<MenuIconName, MenuIconDefinition> = {
    overview: {
        paths: [
            'M4 5.5A1.5 1.5 0 0 1 5.5 4h5v7H4V5.5Z',
            'M13.5 4h5A1.5 1.5 0 0 1 20 5.5V9h-6.5V4Z',
            'M4 13.5h6.5V20h-5A1.5 1.5 0 0 1 4 18.5v-5Z',
            'M13.5 11.5H20v7a1.5 1.5 0 0 1-1.5 1.5h-5v-8.5Z',
        ],
        gradient: 'from-sky-400 via-blue-500 to-indigo-600',
    },
    counter: {
        paths: ['M5 6h14v12H5V6Z', 'M8 10h8', 'M8 14h3', 'M14 14h2'],
        gradient: 'from-violet-400 via-purple-500 to-fuchsia-600',
    },
    cashIn: {
        paths: ['M5 11h14v8H5v-8Z', 'M12 4v9', 'm8.5 9.5 3.5 3.5 3.5-3.5'],
        gradient: 'from-emerald-400 via-teal-500 to-cyan-600',
    },
    cashOut: {
        paths: ['M5 11h14v8H5v-8Z', 'M12 14V5', 'm8.5 8.5 3.5-3.5 3.5 3.5'],
        gradient: 'from-rose-400 via-orange-500 to-amber-500',
    },
    sendMoney: {
        paths: ['M3.5 11.2 20.5 4l-6.6 16-3.3-6.1-7.1-2.7Z', 'm10.6 13.9 9.9-9.9'],
        gradient: 'from-sky-400 via-blue-500 to-indigo-600',
    },
    receiveMoney: {
        paths: [
            'M5 13h4l2 3h2l2-3h4',
            'M5 13l-1 6h16l-1-6',
            'M12 4v8',
            'm8.5 8.5 3.5 3.5 3.5-3.5',
        ],
        gradient: 'from-violet-400 via-fuchsia-500 to-pink-500',
    },
    transfer: {
        paths: ['M4 8h14', 'm15 5 3 3-3 3', 'M20 16H6', 'm9 13-3 3 3 3'],
        gradient: 'from-cyan-400 via-sky-500 to-blue-600',
    },
    accounts: {
        paths: [
            'M4 7.5A2.5 2.5 0 0 1 6.5 5H19v14H6.5A2.5 2.5 0 0 1 4 16.5v-9Z',
            'M4 8h13',
            'M15 13h4',
        ],
        gradient: 'from-blue-400 via-indigo-500 to-violet-600',
    },
    exchange: {
        paths: [
            'M18.5 8A7.5 7.5 0 0 0 6 5.8L4 8',
            'M5.5 16A7.5 7.5 0 0 0 18 18.2L20 16',
            'M4 4v4h4',
            'M20 20v-4h-4',
        ],
        gradient: 'from-amber-400 via-orange-500 to-rose-500',
    },
    floats: {
        paths: [
            'M6 8c0-2 2.7-4 6-4s6 2 6 4-2.7 4-6 4-6-2-6-4Z',
            'M6 8v5c0 2 2.7 4 6 4s6-2 6-4V8',
            'M6 13v3c0 2 2.7 4 6 4s6-2 6-4v-3',
        ],
        gradient: 'from-teal-400 via-cyan-500 to-sky-600',
    },
    vault: {
        paths: ['M5 8h14v11H5V8Z', 'M8 8V5h8v3', 'M12 12.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z'],
        gradient: 'from-emerald-400 via-emerald-500 to-teal-700',
    },
    reconcile: {
        paths: ['M5 7h9', 'M5 12h14', 'M5 17h7', 'M16 6l3 3-3 3'],
        gradient: 'from-teal-400 via-emerald-500 to-cyan-700',
    },
    reports: {
        paths: ['M6 4h9l3 3v13H6V4Z', 'M14 4v4h4', 'M9 13h6', 'M9 17h4'],
        gradient: 'from-blue-400 via-sky-500 to-cyan-600',
    },
    companies: {
        paths: [
            'M4 21V7a2 2 0 0 1 2-2h7v16',
            'M13 9h5a2 2 0 0 1 2 2v10',
            'M8 9h1',
            'M8 13h1',
            'M8 17h1',
            'M16 13h1',
            'M16 17h1',
        ],
        gradient: 'from-purple-400 via-violet-500 to-indigo-600',
    },
    services: {
        paths: ['M4 7h16', 'M4 17h16', 'M8 11V3', 'M16 21v-8'],
        gradient: 'from-orange-400 via-amber-500 to-yellow-600',
    },
    fees: {
        paths: ['M5 6h14', 'M5 12h14', 'M5 18h8', 'M16 15l3 3-3 3'],
        gradient: 'from-pink-400 via-rose-500 to-orange-500',
    },
    users: {
        paths: [
            'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
            'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
            'M22 21v-2a4 4 0 0 0-3-3.87',
            'M16 3.13a4 4 0 0 1 0 7.75',
        ],
        gradient: 'from-fuchsia-400 via-purple-500 to-violet-600',
    },
    transactions: {
        paths: ['M6 3h12v18l-3-2-3 2-3-2-3 2V3Z', 'M9 8h6', 'M9 12h6', 'M9 16h4'],
        gradient: 'from-slate-400 via-slate-600 to-zinc-700',
    },
    settings: {
        paths: [
            'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z',
            'M19.4 15a1.8 1.8 0 0 0 .36 1.98l-1.8 3.12a1.8 1.8 0 0 0-1.98.2 1.8 1.8 0 0 0-.76 1.67H8.8a1.8 1.8 0 0 0-.76-1.67 1.8 1.8 0 0 0-1.98-.2l-1.8-3.12A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.4-1.27v-3.46A1.8 1.8 0 0 0 4.6 9a1.8 1.8 0 0 0-.36-1.98l1.8-3.12a1.8 1.8 0 0 0 1.98-.2A1.8 1.8 0 0 0 8.8 2h6.4a1.8 1.8 0 0 0 .76 1.67 1.8 1.8 0 0 0 1.98.2l1.8 3.12A1.8 1.8 0 0 0 19.4 9a1.8 1.8 0 0 0 1.4 1.27v3.46A1.8 1.8 0 0 0 19.4 15Z',
        ],
        gradient: 'from-zinc-400 via-slate-500 to-slate-700',
    },
};
