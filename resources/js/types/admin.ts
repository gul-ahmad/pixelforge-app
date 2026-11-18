/** Money values are server-formatted strings with 9 decimals, e.g. "$0.012590000". */
export type SpendMoney = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

export type SpendUser = SpendMoney & {
    user_id: number | null;
    name: string;
    email: string | null;
};

export type SpendDay = SpendMoney & {
    date: string;
    pixelforge: { generations: number; cost: string } | null;
    matches: boolean | null;
    difference: string | null;
    users: SpendUser[];
};

export type SpendReport = {
    range: { start: string; end: string };
    pixelforge_available: boolean;
    totals: SpendMoney & {
        pixelforge_cost: string | null;
        matches: boolean | null;
    };
    days: SpendDay[];
    users: SpendUser[];
};
