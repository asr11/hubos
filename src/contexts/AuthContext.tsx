import { hubFetch } from '../lib/hubFetch'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useHubStore } from '../store/useStore'
import { isPublicAppPath } from '../lib/publicRoutes'

export interface User {
  uid: string
  email: string | null
  displayName: string | null
  role?: string
}

export interface AuthContextValue {
  user: User | null
  loading: boolean
  firebaseReady: boolean
  signInWithEmail: (email: string, password: string) => Promise<void>
  signOutUser: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const token = localStorage.getItem('hub_os_token')
    const storedUser = localStorage.getItem('hub_os_user')
    const setCurrentUser = useHubStore.getState().setCurrentUser

    if (token && storedUser) {
      try {
        const u = JSON.parse(storedUser)
        setUser(u)
        setCurrentUser(u)
      } catch {
        localStorage.removeItem('hub_os_token')
        localStorage.removeItem('hub_os_user')
        setUser(null)
        setCurrentUser(null)
        navigate('/login', { replace: true })
      }
    } else {
      setUser(null)
      setCurrentUser(null)
      if (!isPublicAppPath(location.pathname)) {
        navigate('/login', { replace: true })
      }
    }
    setLoading(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const signInWithEmail = useCallback(async (email: string, password: string) => {
    try {
      const res = await hubFetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      })
      
      if (!res.ok) {
        throw new Error('שגיאת התחברות')
      }
      
      const data = await res.json()
      
      const localUser = {
        uid: data.user.id,
        email: data.user.email,
        displayName: 'המנכ"לית',
        role: data.user.role,
        avatar: 'CEO',
        color: '#F97316',
        active: true
      }
      
      localStorage.setItem('hub_os_token', data.token)
      localStorage.setItem('hub_os_user', JSON.stringify(localUser))
      
      setUser(localUser as any)
      useHubStore.getState().setCurrentUser(localUser)
      navigate('/', { replace: true })
    } catch (e) {
      console.error(e)
      throw new Error('אימייל או סיסמה שגויים')
    }
  }, [navigate])

  const signOutUser = useCallback(async () => {
    localStorage.removeItem('hub_os_token')
    localStorage.removeItem('hub_os_user')
    setUser(null)
    useHubStore.getState().setCurrentUser(null)
    navigate('/login', { replace: true })
  }, [navigate])

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      loading,
      firebaseReady: true, // Bypass firebase checks in UI
      signInWithEmail,
      signOutUser,
    }),
    [user, loading, signInWithEmail, signOutUser],
  )

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error('useAuth חייב לרוץ בתוך AuthProvider')
  }
  return ctx
}
