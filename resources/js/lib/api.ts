export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE';

export type ApiRequestOptions = {
    token?: string | null;
    method?: HttpMethod;
    body?: Record<string, unknown> | FormData;
    query?: Record<string, string | number | boolean | null | undefined>;
};

export class ApiRequestError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly errors?: Record<string, string[]>,
    ) {
        super(message);
        this.name = 'ApiRequestError';
    }
}

export async function apiRequest<T>(
    path: string,
    options: ApiRequestOptions = {},
): Promise<T> {
    const url = withQuery(path, options.query);
    const body = options.body;
    const requestBody =
        body === undefined
            ? undefined
            : isFormDataBody(body)
              ? body
              : JSON.stringify(body);
    const headers: HeadersInit = {
        Accept: 'application/json',
    };

    if (body !== undefined && !isFormDataBody(body)) {
        headers['Content-Type'] = 'application/json';
    }

    if (options.token) {
        headers.Authorization = `Bearer ${options.token}`;
    }

    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers,
        body: requestBody,
    });
    const payload = await parseJson(response);

    if (!response.ok) {
        const message =
            typeof payload?.message === 'string'
                ? payload.message
                : `Request failed with status ${response.status}`;
        const errors =
            payload?.errors && typeof payload.errors === 'object'
                ? (payload.errors as Record<string, string[]>)
                : undefined;

        throw new ApiRequestError(message, response.status, errors);
    }

    return payload as T;
}

function isFormDataBody(body: ApiRequestOptions['body']): body is FormData {
    return typeof FormData !== 'undefined' && body instanceof FormData;
}

function withQuery(path: string, query?: ApiRequestOptions['query']): string {
    if (!query) {
        return path;
    }

    const params = new URLSearchParams();

    Object.entries(query).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    });

    const queryString = params.toString();

    return queryString ? `${path}?${queryString}` : path;
}

async function parseJson(
    response: Response,
): Promise<Record<string, unknown> | null> {
    const text = await response.text();

    if (!text) {
        return null;
    }

    try {
        return JSON.parse(text) as Record<string, unknown>;
    } catch {
        return { message: text };
    }
}
