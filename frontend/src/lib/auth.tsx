"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

import { ApiError, api, primeCsrf } from "@/lib/api";
import type { AuthResponse, Role, User } from "@/lib/types";

type AuthStatus = "loading" | "authenticated" | "guest";

interface AuthContextValue {
  user: User | null;
  status: AuthStatus;
  login: (username: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  refresh: () => Promise<User | null>;
  hasRole: (...roles: Role[]) => boolean;
  isAdmin: boolean;
  isPesoManager: boolean;
  isIntern: boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  const refresh = useCallback(async (): Promise<User | null> => {
    try {
      const response = await api.get<{ user: User }>("/api/v1/auth/user");
      setUser(response.user);
      setStatus("authenticated");
      return response.user;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setUser(null);
        setStatus("guest");
        return null;
      }
      throw error;
    }
  }, []);

  useEffect(() => {
    refresh().catch(() => setStatus("guest"));
  }, [refresh]);

  const login = useCallback(async (username: string, password: string) => {
    await primeCsrf();
    const response = await api.post<AuthResponse>("/api/v1/auth/login", { username, password });
    setUser(response.user);
    setStatus("authenticated");
    return response.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post("/api/v1/auth/logout");
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const value = useMemo<AuthContextValue>(() => {
    const hasRole = (...roles: Role[]) => !!user && roles.includes(user.role);
    return {
      user,
      status,
      login,
      logout,
      refresh,
      hasRole,
      isAdmin: hasRole("admin"),
      isPesoManager: hasRole("peso_manager"),
      isIntern: hasRole("intern"),
    };
  }, [user, status, login, logout, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used inside <AuthProvider>");
  }
  return context;
}
