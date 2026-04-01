/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useEffect, useRef, useState } from 'react';
import {
  Check,
  ChevronLeft,
  ChevronRight,
  Copy,
  Edit,
  FileCode,
  FileText,
  Folder,
  Image as ImageIcon,
  LogOut,
  Plus,
  Save,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import Editor from '@monaco-editor/react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

declare global {
  interface Window {
    __APP_CONFIG__?: {
      baseUrl: string;
      apiEndpoint: string;
      cdnBase: string;
      debug: boolean;
    };
  }
}

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

const appConfig = window.__APP_CONFIG__ ?? {
  baseUrl: '',
  apiEndpoint: '/api/index.php',
  cdnBase: '/cdn',
  debug: false,
};

const buildDebugId = '2026-03-10-php-debug-1';
const isDebugEnabled = appConfig.debug === true;

const dumpHeaders = (headers: Headers) => Object.fromEntries(headers.entries());

const summarizeBody = (body: string) => body.slice(0, 500);

const debugInfo = (...args: unknown[]) => {
  if (isDebugEnabled) {
    console.info(...args);
  }
};

const debugWarn = (...args: unknown[]) => {
  if (isDebugEnabled) {
    console.warn(...args);
  }
};

const debugError = (...args: unknown[]) => {
  if (isDebugEnabled) {
    console.error(...args);
  }
};

const copyText = async (text: string) => {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.top = '-9999px';
  textarea.style.left = '-9999px';
  document.body.appendChild(textarea);
  textarea.focus();
  textarea.select();

  try {
    const succeeded = document.execCommand('copy');
    if (!succeeded) {
      throw new Error('document.execCommand("copy") returned false');
    }
  } finally {
    document.body.removeChild(textarea);
  }
};

const logRequestContext = (label: string, url: string) => {
  debugInfo(label, {
    buildDebugId,
    url,
    href: window.location.href,
    origin: window.location.origin,
    pathname: window.location.pathname,
    appConfig,
  });
};

const apiUrl = (path: string, query?: Record<string, string>) => {
  const route = path.replace(/^\/+/, '');
  const params = new URLSearchParams({ route });

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      params.set(key, value);
    }
  }

  return `${appConfig.apiEndpoint}?${params.toString()}`;
};

const publicCdnUrl = (path: string) => {
  const encodedPath = path
    .split('/')
    .filter(Boolean)
    .map((segment) => encodeURIComponent(segment))
    .join('/');

  const cdnBase = appConfig.cdnBase.replace(/\/+$/, '');
  return `${window.location.origin}${cdnBase}/${encodedPath}`;
};

interface FileItem {
  name: string;
  isDirectory: boolean;
  size: number;
  mtime: string;
  path: string;
}

interface User {
  username: string;
}

const Login = ({ onLogin }: { onLogin: (user: User) => void }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [isLoggingIn, setIsLoggingIn] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoggingIn(true);
    setError('');

    try {
      const url = apiUrl('/login');
      logRequestContext('[Auth] Attempting login', url);
      debugInfo('[Auth] Login username', username);
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ username, password }),
      });
      debugInfo('[Auth] Login response meta', {
        status: res.status,
        statusText: res.statusText,
        redirected: res.redirected,
        responseUrl: res.url,
        headers: dumpHeaders(res.headers),
      });

      if (res.ok) {
        const data = await res.json();
        debugInfo('[Auth] Login payload', data);
        onLogin(data.user);
      } else {
        const rawBody = await res.text();
        debugWarn('[Auth] Login failed', {
          status: res.status,
          statusText: res.statusText,
          responseUrl: res.url,
          headers: dumpHeaders(res.headers),
          rawBody: summarizeBody(rawBody),
        });
        const data = (() => {
          try {
            return JSON.parse(rawBody);
          } catch {
            return { error: `Erro desconhecido (HTTP ${res.status})` };
          }
        })();
        setError(data.error || 'Credenciais invalidas');
      }
    } catch (error) {
      debugError('[Auth] Login request failed', error);
      setError('Erro ao conectar ao servidor');
    } finally {
      setIsLoggingIn(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-[#0a0a0a] text-white">
      <div className="w-full max-w-md p-8 bg-[#141414] border border-white/10 rounded-2xl shadow-2xl">
        <h1 className="text-3xl font-bold mb-6 text-center tracking-tight">CDN Manager</h1>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-white/60 mb-1">Usuario</label>
            <input
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              className="w-full px-4 py-3 bg-black border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all"
              placeholder="admin"
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-white/60 mb-1">Senha</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-4 py-3 bg-black border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-all"
              placeholder="********"
              required
            />
          </div>
          {error && <p className="text-red-500 text-sm">{error}</p>}
          <button
            type="submit"
            disabled={isLoggingIn}
            className="w-full py-3 bg-emerald-600 hover:bg-emerald-500 disabled:bg-emerald-800 disabled:cursor-not-allowed text-white font-semibold rounded-xl transition-all shadow-lg shadow-emerald-900/20 flex items-center justify-center gap-2"
          >
            {isLoggingIn ? (
              <>
                <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                Autenticando...
              </>
            ) : (
              'Acessar Sistema'
            )}
          </button>
        </form>
      </div>
    </div>
  );
};

const FileIcon = ({ item }: { item: FileItem }) => {
  if (item.isDirectory) {
    return <Folder className="w-5 h-5 text-emerald-400 fill-emerald-400/20" />;
  }

  const ext = item.name.split('.').pop()?.toLowerCase();
  if (['js', 'ts', 'tsx', 'jsx', 'json', 'html', 'css', 'php', 'py', 'go'].includes(ext || '')) {
    return <FileCode className="w-5 h-5 text-blue-400" />;
  }

  if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(ext || '')) {
    return <ImageIcon className="w-5 h-5 text-purple-400" />;
  }

  return <FileText className="w-5 h-5 text-white/40" />;
};

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [currentPath, setCurrentPath] = useState('');
  const [files, setFiles] = useState<FileItem[]>([]);
  const [editingFile, setEditingFile] = useState<{ path: string; content: string } | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [dragActive, setDragActive] = useState(false);
  const [copiedPath, setCopiedPath] = useState<string | null>(null);
  const [modal, setModal] = useState<{
    type: 'folder' | 'conflict' | 'rename' | 'error';
    title: string;
    message?: string;
    inputValue?: string;
    onConfirm: (value?: string) => void;
    resolveConflict?: (action: 'replace' | 'rename' | 'skip') => void;
  } | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const apiFetch = async (url: string, options: RequestInit = {}) => {
    const headers = {
      ...options.headers,
    };

    return fetch(url, {
      ...options,
      headers,
      credentials: 'same-origin',
    });
  };

  useEffect(() => {
    debugInfo('[CDN Manager] Build', buildDebugId);
    debugInfo('[CDN Manager] Runtime', {
      appConfig,
      href: window.location.href,
      origin: window.location.origin,
      pathname: window.location.pathname,
      userAgent: navigator.userAgent,
    });
    checkAuth();
  }, []);

  useEffect(() => {
    if (user) {
      fetchFiles();
    }
  }, [user, currentPath]);

  const checkAuth = async () => {
    try {
      const url = apiUrl('/me');
      logRequestContext('[Auth] Checking session', url);
      const res = await apiFetch(url, {
        headers: { Accept: 'application/json' },
      });
      debugInfo('[Auth] Session response meta', {
        status: res.status,
        statusText: res.statusText,
        redirected: res.redirected,
        responseUrl: res.url,
        headers: dumpHeaders(res.headers),
      });

      if (res.ok) {
        const data = await res.json();
        debugInfo('[Auth] Session payload', data);
        setUser(data.user);
      } else {
        const body = await res.text();
        debugWarn('[Auth] Session check failed', {
          status: res.status,
          statusText: res.statusText,
          responseUrl: res.url,
          headers: dumpHeaders(res.headers),
          body: summarizeBody(body),
        });
        setUser(null);
      }
    } catch (error) {
      debugError('[Auth] Session check request failed', error);
      setUser(null);
    } finally {
      setLoading(false);
    }
  };

  const handleLoginSuccess = (loggedUser: User) => {
    setUser(loggedUser);
  };

  const fetchFiles = async () => {
    try {
      const url = apiUrl('/files', { path: currentPath });
      logRequestContext('[Files] Listing', url);
      const res = await apiFetch(url, {
        headers: { Accept: 'application/json' },
      });
      debugInfo('[Files] Response meta', {
        status: res.status,
        statusText: res.statusText,
        redirected: res.redirected,
        responseUrl: res.url,
        headers: dumpHeaders(res.headers),
      });

      if (res.ok) {
        const data = await res.json();
        debugInfo('[Files] Items loaded', data);
        setFiles(data);
      } else if (res.status === 401) {
        const body = await res.text();
        debugWarn('[Files] Unauthorized', {
          body: summarizeBody(body),
          responseUrl: res.url,
        });
        setUser(null);
      } else {
        const body = await res.text();
        debugWarn('[Files] Non-success response', {
          status: res.status,
          statusText: res.statusText,
          responseUrl: res.url,
          headers: dumpHeaders(res.headers),
          body: summarizeBody(body),
        });
      }
    } catch (err) {
      debugError('[Files] Request failed', err);
    }
  };

  const handleLogout = async () => {
    const url = apiUrl('/logout');
    debugInfo('[Auth] Logout', url);
    await apiFetch(url, { method: 'POST' }).catch((error) => {
      debugError('[Auth] Logout request failed', error);
      return null;
    });
    setUser(null);
  };

  const createFolder = () => {
    setModal({
      type: 'folder',
      title: 'Nova Pasta',
      inputValue: '',
      onConfirm: async (name) => {
        if (!name) {
          return;
        }

        const newPath = currentPath ? `${currentPath}/${name}` : name;
        try {
          const res = await apiFetch(apiUrl('/mkdir'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: newPath }),
          });

          if (res.ok) {
            fetchFiles();
            setModal(null);
          } else {
            alert('Erro ao criar pasta');
          }
        } catch (err) {
          console.error(err);
        }
      },
    });
  };

  const deleteItem = async (path: string) => {
    if (!confirm('Tem certeza que deseja excluir este item?')) {
      return;
    }

    await apiFetch(apiUrl('/delete', { path }), { method: 'DELETE' });
    fetchFiles();
  };

  const renameItem = (item: FileItem) => {
    setModal({
      type: 'rename',
      title: item.isDirectory ? 'Renomear Pasta' : 'Renomear Arquivo',
      inputValue: item.name,
      onConfirm: async (value) => {
        const newName = (value || '').trim();
        if (!newName) {
          setModal(null);
          return;
        }

        try {
          const res = await apiFetch(apiUrl('/rename'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: item.path, newName }),
          });

          if (res.ok) {
            const data = await res.json();
            if (editingFile?.path === item.path) {
              setEditingFile({ ...editingFile, path: data.item.newPath });
            }
            setModal(null);
            fetchFiles();
            return;
          }

          const data = await res.json().catch(() => ({ error: 'Erro ao renomear item' }));
          alert(data.error || 'Erro ao renomear item');
        } catch (err) {
          console.error(err);
          alert('Erro ao renomear item');
        }
      },
    });
  };

  const editFile = async (path: string) => {
    const res = await apiFetch(apiUrl('/read', { path }));
    if (res.ok) {
      const data = await res.json();
      setEditingFile({ path, content: data.content });
    }
  };

  const saveFile = async () => {
    if (!editingFile) {
      return;
    }

    await apiFetch(apiUrl('/save'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ path: editingFile.path, content: editingFile.content }),
    });

    setEditingFile(null);
    fetchFiles();
  };

  const getAllFilesFromEntry = async (entry: any, path = ''): Promise<{ file: File; subPath: string }[]> => {
    if (entry.isFile) {
      return new Promise((resolve) => {
        entry.file((file: File) => {
          resolve([{ file, subPath: path }]);
        });
      });
    }

    if (entry.isDirectory) {
      const reader = entry.createReader();
      const entries = await new Promise<any[]>((resolve) => {
        reader.readEntries(resolve);
      });

      const results = await Promise.all(
        entries.map((childEntry) =>
          getAllFilesFromEntry(childEntry, path ? `${path}/${entry.name}` : entry.name),
        ),
      );

      return results.flat();
    }

    return [];
  };

  const suggestName = (name: string) => {
    const lastDotIndex = name.lastIndexOf('.');
    const baseName = lastDotIndex === -1 ? name : name.substring(0, lastDotIndex);
    const extension = lastDotIndex === -1 ? '' : name.substring(lastDotIndex);

    let counter = 1;
    let newName = `${baseName} (${counter})${extension}`;

    while (files.some((file) => file.name === newName && !file.isDirectory)) {
      counter++;
      newName = `${baseName} (${counter})${extension}`;
    }

    return newName;
  };

  const handleUpload = async (filesToUpload: (File | { file: File; subPath: string })[]) => {
    if (filesToUpload.length === 0) {
      return;
    }

    setIsUploading(true);

    for (const item of filesToUpload) {
      const file = 'file' in item ? item.file : item;
      const subPath = 'subPath' in item ? item.subPath : '';

      let fileName = file.name;
      let uploadAction: 'upload' | 'skip' = 'upload';

      while (true) {
        const existingFile = subPath === '' ? files.find((listedFile) => listedFile.name === fileName && !listedFile.isDirectory) : null;
        if (!existingFile) {
          break;
        }

        const action = await new Promise<'replace' | 'rename' | 'skip'>((resolve) => {
          setModal({
            type: 'conflict',
            title: 'Arquivo ja existe',
            message: `O arquivo "${fileName}" ja existe nesta pasta. O que deseja fazer?`,
            onConfirm: () => {},
            resolveConflict: (resolvedAction) => resolve(resolvedAction),
          });
        });

        if (action === 'skip') {
          uploadAction = 'skip';
          setModal(null);
          break;
        }

        if (action === 'replace') {
          setModal(null);
          break;
        }

        const suggested = suggestName(fileName);
        const newName = await new Promise<string | null>((resolve) => {
          setModal({
            type: 'rename',
            title: 'Renomear Arquivo',
            inputValue: suggested,
            onConfirm: (value) => resolve(value || null),
          });
        });

        setModal(null);
        if (!newName || newName === fileName) {
          continue;
        }

        fileName = newName;
      }

      if (uploadAction === 'skip') {
        continue;
      }

      const formData = new FormData();
      formData.append('path', currentPath);
      formData.append('fileSubPath', subPath);
      formData.append('customName', fileName);
      formData.append('files', file);

      try {
        await apiFetch(apiUrl('/upload'), {
          method: 'POST',
          body: formData,
        });
      } catch (err) {
        console.error(err);
      }
    }

    setIsUploading(false);
    fetchFiles();
  };

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();

    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleDrop = async (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);

    const items = Array.from(e.dataTransfer.items);
    const uploadQueue: { file: File; subPath: string }[] = [];

    for (const item of items) {
      const entry = (item as any).webkitGetAsEntry();
      if (entry) {
        const droppedFiles = await getAllFilesFromEntry(entry);
        uploadQueue.push(...droppedFiles);
      }
    }

    if (uploadQueue.length > 0) {
      handleUpload(uploadQueue);
    }
  };

  const copyLink = (path: string) => {
    const url = publicCdnUrl(path);
    copyText(url)
      .then(() => {
        console.info('[Clipboard] Public link copied', { path, url });
        setCopiedPath(path);
        setTimeout(() => setCopiedPath(null), 2000);
      })
      .catch((error) => {
        debugError('[Clipboard] Failed to copy public link', { path, url, error });
        alert(`Nao foi possivel copiar automaticamente.\n\nLink: ${url}`);
      });
  };

  const navigateUp = () => {
    const parts = currentPath.split('/');
    parts.pop();
    setCurrentPath(parts.join('/'));
  };

  if (loading) {
    return <div className="min-h-screen bg-black flex items-center justify-center text-white">Carregando...</div>;
  }

  if (!user) {
    return <Login onLogin={handleLoginSuccess} />;
  }

  return (
    <div className="min-h-screen bg-[#0a0a0a] text-white font-sans selection:bg-emerald-500/30">
      <header className="h-16 border-bottom border-white/10 bg-[#141414]/80 backdrop-blur-md sticky top-0 z-50 flex items-center justify-between px-6">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 bg-emerald-600 rounded-lg flex items-center justify-center font-bold">C</div>
          <h1 className="text-xl font-bold tracking-tight">CDN Manager</h1>
        </div>
        <div className="flex items-center gap-4">
          <span className="text-sm text-white/60">Ola, {user.username}</span>
          <button
            onClick={handleLogout}
            className="p-2 hover:bg-white/10 rounded-lg transition-colors text-white/60 hover:text-white"
          >
            <LogOut className="w-5 h-5" />
          </button>
        </div>
      </header>

      <main className="max-w-7xl mx-auto p-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
          <div className="flex items-center gap-2 text-sm">
            <button onClick={() => setCurrentPath('')} className="hover:text-emerald-400 transition-colors">
              Raiz
            </button>
            {currentPath
              .split('/')
              .filter(Boolean)
              .map((part, i, arr) => (
                <React.Fragment key={i}>
                  <ChevronRight className="w-4 h-4 text-white/20" />
                  <button
                    onClick={() => setCurrentPath(arr.slice(0, i + 1).join('/'))}
                    className="hover:text-emerald-400 transition-colors"
                  >
                    {part}
                  </button>
                </React.Fragment>
              ))}
          </div>

          <div className="flex items-center gap-3">
            <button
              onClick={createFolder}
              className="flex items-center gap-2 px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all text-sm font-medium"
            >
              <Plus className="w-4 h-4" /> Nova Pasta
            </button>
            <button
              onClick={() => fileInputRef.current?.click()}
              className="flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl transition-all text-sm font-medium shadow-lg shadow-emerald-900/20"
            >
              <Upload className="w-4 h-4" /> Subir Arquivos
            </button>
            <input
              type="file"
              multiple
              webkitdirectory=""
              className="hidden"
              ref={fileInputRef}
              onChange={(e) => handleUpload(Array.from(e.target.files || []))}
            />
          </div>
        </div>

        <div
          className={cn(
            'bg-[#141414] border border-white/10 rounded-2xl overflow-hidden transition-all',
            dragActive && 'ring-2 ring-emerald-500 bg-emerald-500/5',
          )}
          onDragEnter={handleDrag}
          onDragLeave={handleDrag}
          onDragOver={handleDrag}
          onDrop={handleDrop}
        >
          <div className="grid grid-cols-[1fr_120px_180px_160px] px-6 py-4 border-b border-white/5 text-xs font-semibold uppercase tracking-wider text-white/40">
            <div>Nome</div>
            <div>Tamanho</div>
            <div>Modificado</div>
            <div className="text-right">Acoes</div>
          </div>

          <div className="divide-y divide-white/5">
            {currentPath && (
              <div
                onClick={navigateUp}
                className="grid grid-cols-[1fr_120px_180px_160px] px-6 py-4 hover:bg-white/5 cursor-pointer transition-colors group"
              >
                <div className="flex items-center gap-3">
                  <ChevronLeft className="w-5 h-5 text-white/20 group-hover:text-emerald-400" />
                  <span className="text-sm font-medium">Voltar</span>
                </div>
                <div>-</div>
                <div>-</div>
                <div></div>
              </div>
            )}

            {files.length === 0 && (
              <div className="px-6 py-12 text-center text-white/40">
                <Folder className="w-12 h-12 mx-auto mb-3 opacity-20" />
                <p>Esta pasta esta vazia</p>
              </div>
            )}

            {files.map((file) => (
              <div
                key={file.path}
                className="grid grid-cols-[1fr_120px_180px_160px] px-6 py-4 hover:bg-white/5 items-center transition-colors group"
              >
                <div
                  className="flex items-center gap-3 cursor-pointer overflow-hidden"
                  onClick={() => (file.isDirectory ? setCurrentPath(file.path) : editFile(file.path))}
                >
                  <FileIcon item={file} />
                  <span className="text-sm font-medium truncate">{file.name}</span>
                </div>
                <div className="text-sm text-white/40">{file.isDirectory ? '-' : `${(file.size / 1024).toFixed(1)} KB`}</div>
                <div className="text-sm text-white/40">{new Date(file.mtime).toLocaleDateString()}</div>
                <div className="flex items-center justify-end gap-1">
                  <button
                    onClick={() => renameItem(file)}
                    title={file.isDirectory ? 'Renomear Pasta' : 'Renomear Arquivo'}
                    className="p-2 hover:bg-white/10 rounded-lg transition-colors text-white/40 hover:text-amber-400"
                  >
                    <Edit className="w-4 h-4" />
                  </button>
                  {!file.isDirectory && (
                    <button
                      onClick={() => copyLink(file.path)}
                      title="Copiar Link Publico"
                      className="p-2 hover:bg-white/10 rounded-lg transition-colors text-white/40 hover:text-emerald-400"
                    >
                      {copiedPath === file.path ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                    </button>
                  )}
                  {!file.isDirectory && (
                    <button
                      onClick={() => editFile(file.path)}
                      title="Editar Arquivo"
                      className="p-2 hover:bg-white/10 rounded-lg transition-colors text-white/40 hover:text-blue-400"
                    >
                      <FileCode className="w-4 h-4" />
                    </button>
                  )}
                  <button
                    onClick={() => deleteItem(file.path)}
                    title="Excluir"
                    className="p-2 hover:bg-white/10 rounded-lg transition-colors text-white/40 hover:text-red-400"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </main>

      {modal && (
        <div className="fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
          <div className="w-full max-w-md bg-[#141414] border border-white/10 rounded-2xl shadow-2xl overflow-hidden">
            <div className="p-6 border-b border-white/10 flex items-center justify-between">
              <h3 className="text-lg font-bold">{modal.title}</h3>
              <button onClick={() => setModal(null)} className="p-1 hover:bg-white/10 rounded-lg">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6">
              {modal.type === 'folder' && (
                <div className="space-y-4">
                  <input
                    autoFocus
                    type="text"
                    value={modal.inputValue}
                    onChange={(e) => setModal({ ...modal, inputValue: e.target.value })}
                    onKeyDown={(e) => e.key === 'Enter' && modal.onConfirm(modal.inputValue)}
                    className="w-full px-4 py-2 bg-black border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                    placeholder="Nome da pasta"
                  />
                  <div className="flex justify-end gap-3">
                    <button onClick={() => setModal(null)} className="px-4 py-2 text-sm font-medium hover:bg-white/5 rounded-lg">
                      Cancelar
                    </button>
                    <button
                      onClick={() => modal.onConfirm(modal.inputValue)}
                      className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-lg"
                    >
                      Criar Pasta
                    </button>
                  </div>
                </div>
              )}

              {modal.type === 'rename' && (
                <div className="space-y-4">
                  <p className="text-white/60 text-xs uppercase tracking-wider font-semibold">Novo nome</p>
                  <input
                    autoFocus
                    type="text"
                    value={modal.inputValue}
                    onChange={(e) => setModal({ ...modal, inputValue: e.target.value })}
                    onKeyDown={(e) => e.key === 'Enter' && modal.onConfirm(modal.inputValue)}
                    className="w-full px-4 py-2 bg-black border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                    placeholder="Nome do item"
                  />
                  <div className="flex justify-end gap-3">
                    <button
                      onClick={() => modal.onConfirm(undefined)}
                      className="px-4 py-2 text-sm font-medium hover:bg-white/5 rounded-lg"
                    >
                      Cancelar
                    </button>
                    <button
                      onClick={() => modal.onConfirm(modal.inputValue)}
                      className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-lg"
                    >
                      Renomear
                    </button>
                  </div>
                </div>
              )}

              {modal.type === 'conflict' && (
                <div className="space-y-6">
                  <p className="text-white/60 text-sm leading-relaxed">{modal.message}</p>
                  <div className="grid grid-cols-1 gap-2">
                    <button
                      onClick={() => modal.resolveConflict?.('replace')}
                      className="w-full py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-lg"
                    >
                      Substituir Existente
                    </button>
                    <button
                      onClick={() => modal.resolveConflict?.('rename')}
                      className="w-full py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-lg"
                    >
                      Renomear Novo Arquivo
                    </button>
                    <button
                      onClick={() => modal.resolveConflict?.('skip')}
                      className="w-full py-2 bg-white/5 hover:bg-white/10 text-white text-sm font-bold rounded-lg"
                    >
                      Pular este Arquivo
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {editingFile && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
          <div className="w-full max-w-6xl h-[80vh] bg-[#141414] border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            <div className="h-14 border-b border-white/10 flex items-center justify-between px-6 bg-black/20">
              <div className="flex items-center gap-3">
                <FileCode className="w-5 h-5 text-emerald-400" />
                <span className="text-sm font-medium truncate max-w-md">{editingFile.path}</span>
              </div>
              <div className="flex items-center gap-3">
                <button
                  onClick={saveFile}
                  className="flex items-center gap-2 px-4 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-all text-xs font-semibold"
                >
                  <Save className="w-4 h-4" /> Salvar
                </button>
                <button onClick={() => setEditingFile(null)} className="p-2 hover:bg-white/10 rounded-lg transition-colors">
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>
            <div className="flex-1">
              <Editor
                height="100%"
                defaultLanguage="javascript"
                theme="vs-dark"
                value={editingFile.content}
                onChange={(value) => setEditingFile({ ...editingFile, content: value || '' })}
                options={{
                  minimap: { enabled: false },
                  fontSize: 14,
                  padding: { top: 20 },
                  scrollBeyondLastLine: false,
                  automaticLayout: true,
                }}
              />
            </div>
          </div>
        </div>
      )}

      {isUploading && (
        <div className="fixed bottom-6 right-6 z-[100] bg-emerald-600 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4 animate-in fade-in slide-in-from-bottom-4">
          <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
          <span className="font-medium">Subindo arquivos...</span>
        </div>
      )}
    </div>
  );
}
