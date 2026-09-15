export async function hubFetch(url: string | Request | URL, options?: RequestInit) {
  const token = localStorage.getItem('hub_os_token');
  const headers = new Headers(options?.headers);
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }
  const res = await fetch(url, {
    ...options,
    headers,
  });

  if (res.status === 401 && !String(url).includes('/api/auth/login')) {
    localStorage.removeItem('hub_os_token');
    localStorage.removeItem('hub_os_user');
    if (window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
  }

  return res;
}
