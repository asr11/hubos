import {
  Component,
  type ErrorInfo,
  type ReactNode,
} from 'react'

interface Props {
  children: ReactNode
  fallback?: ReactNode
  resetKey?: unknown
  onReset?: () => void
}

interface State {
  hasError: boolean
  error: Error | null
}

/**
 * Error Boundary גלובלי — מונע מסך לבן.
 * שגיאת JS ברכיב בודד לא מורידה את כל האפליקציה.
 */
export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('[ErrorBoundary]', error, info.componentStack)
  }

  componentDidUpdate(prevProps: Props) {
    if (this.state.hasError && prevProps.resetKey !== this.props.resetKey) {
      this.resetError()
    }
  }

  resetError = () => {
    this.props.onReset?.()
    this.setState({ hasError: false, error: null })
  }

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) return this.props.fallback

      return (
        <div
          dir="rtl"
          style={{
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
            minHeight: '60vh',
            gap: '1rem',
            fontFamily: 'Rubik, sans-serif',
            padding: '2rem',
          }}
        >
          <div style={{ fontSize: '3rem' }}>⚠️</div>
          <h2 style={{ margin: 0, color: '#1e293b' }}>משהו השתבש</h2>
          <p style={{ color: '#64748b', maxWidth: '400px', textAlign: 'center' }}>
            אירעה שגיאה לא צפויה. נסה לרענן את הדף.
          </p>
          <pre
            style={{
              background: '#fef2f2',
              border: '1px solid #fecaca',
              borderRadius: '0.5rem',
              padding: '0.75rem 1rem',
              fontSize: '0.75rem',
              color: '#dc2626',
              maxWidth: '500px',
              overflow: 'auto',
              direction: 'ltr',
            }}
          >
            {this.state.error?.message}
          </pre>
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <button
              onClick={this.resetError}
              style={{
                background: '#f97316',
                color: '#fff',
                border: 'none',
                borderRadius: '0.5rem',
                padding: '0.5rem 1.5rem',
                cursor: 'pointer',
                fontSize: '0.875rem',
                fontFamily: 'Rubik, sans-serif',
              }}
            >
              נסה שוב
            </button>
            <button
              onClick={() => window.location.reload()}
              style={{
                background: '#e2e8f0',
                color: '#1e293b',
                border: 'none',
                borderRadius: '0.5rem',
                padding: '0.5rem 1.5rem',
                cursor: 'pointer',
                fontSize: '0.875rem',
                fontFamily: 'Rubik, sans-serif',
              }}
            >
              רענן את הדף
            </button>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
