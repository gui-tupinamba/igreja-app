import type { User } from "./types";

export function isAdminOrPastor(user?: User | null) {
  return user?.role === "ADMIN" || user?.role === "PASTOR";
}

export function isLeader(user?: User | null) {
  return user?.role === "LEADER";
}

export function canCreatePost(user?: User | null) {
  return isAdminOrPastor(user) || isLeader(user);
}

export function canPublishPost(user?: User | null) {
  return isAdminOrPastor(user);
}

export function canSubmitPostForReview(user?: User | null) {
  return isLeader(user);
}
