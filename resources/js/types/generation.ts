export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'rejected';

/** Money always arrives pre-formatted from the server (e.g. "$0.0123"); never do maths on it here. */
export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    is_terminal: boolean;
    estimate: { min: string; max: string };
    charged: string | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
};

export type CostEstimate = {
    min: string;
    max: string;
    min_micro_usd: number;
    max_micro_usd: number;
    min_tokens: Record<string, number>;
    max_tokens: Record<string, number>;
};

export type SelectOption = { value: string; label: string };

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: { current_page: number; last_page: number; total: number };
};
