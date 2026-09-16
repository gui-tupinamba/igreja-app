export type Role = "ADMIN" | "PASTOR" | "LEADER" | "MEMBER";
export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  status: string;
  phone?: string | null;
  birth_date?: string | null;
}
export interface Ministry {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  status: string;
}
export interface Permissions {
  manage_users: boolean;
  manage_ministries: boolean;
  manage_settings: boolean;
  led_ministries: { id: number; name: string }[];
}
export interface Page<T> {
  items: T[];
  pagination: { page: number; limit: number; total: number };
}

export interface ContentImage {
  id: number;
  position: number;
  mime_type: string;
  size: number;
  url: string;
}

export interface Content {
  id: number;
  title: string;
  content?: string;
  description?: string | null;
  ministry_id: number | null;
  visibility: string;
  status: string;
  author_id?: number;
  created_by?: number;
  comments_enabled?: boolean;
  images?: ContentImage[];
  starts_at?: string;
  ends_at?: string | null;
  published_at?: string | null;
  created_at: string;
  updated_at: string;
  location?: string | null;
  address?: string | null;
  kind?: "EVENT" | "ACTIVITY";
}
export interface Comment {
  id: number;
  post_id: number;
  user_id: number;
  content: string;
  status: string;
  created_at: string;
  updated_at: string;
}
export interface Membership {
  id: number;
  user_id: number;
  ministry_id: number;
  name: string;
  role: Role;
  status: string;
  user_status: string;
  is_leader: boolean;
}
export interface Profile {
  user: User;
  ministries: { id: number; name: string }[];
  led_ministries: { id: number; name: string }[];
}
