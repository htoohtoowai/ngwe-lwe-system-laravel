const tokenKey = 'ngwe_lwe_api_token';

export function readStoredToken(): string {
    if (typeof window === 'undefined') {
        return '';
    }

    return localStorage.getItem(tokenKey) ?? '';
}

export function storeToken(value: string): void {
    if (typeof window !== 'undefined') {
        localStorage.setItem(tokenKey, value);
    }
}

export function removeStoredToken(): void {
    if (typeof window !== 'undefined') {
        localStorage.removeItem(tokenKey);
    }
}
