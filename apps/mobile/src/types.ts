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

export interface Pagination {
  page: number;
  limit: number;
  total: number;
}

export interface Page<T> {
  items: T[];
  pagination: Pagination;
}

export interface ContentImage {
  id: number;
  url: string;
  full_url?: string;
  detail_url?: string;
  feed_url?: string;
}

export interface Content {
  id: number;
  title: string;
  content?: string;
  description?: string | null;
  status: string;
  visibility: string;
  ministry_id: number | null;
  starts_at?: string;
  ends_at?: string | null;
  location?: string | null;
  address?: string | null;
  comments_enabled?: boolean;
  images?: ContentImage[];
  kind?: "EVENT" | "ACTIVITY";
}

export interface Ministry {
  id: number;
  name: string;
  slug?: string;
  description?: string | null;
  status?: string;
}

export interface Profile {
  user: User;
  ministries: Ministry[];
  led_ministries: Ministry[];
}

export interface Comment {
  id: number;
  user_id: number;
  content: string;
  status: string;
  created_at: string;
}
