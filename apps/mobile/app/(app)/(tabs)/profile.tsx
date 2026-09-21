import { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { api, changePasswordLocallyComplete } from "@/api";
import { Badge, Card, ErrorState, Heading, Loading, Screen, common } from "@/components";
import { useSession } from "@/session";
import { colors } from "@/theme";
import { useResource } from "@/useResource";
import type { Profile as ProfileData, User } from "@/types";

export default function Profile() {
  const { logout, updateUser } = useSession();
  const resource = useResource<ProfileData>("/profile");
  const [name, setName] = useState(""); const [phone, setPhone] = useState(""); const [birthDate, setBirthDate] = useState("");
  const [currentPassword, setCurrentPassword] = useState(""); const [newPassword, setNewPassword] = useState(""); const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<unknown>(); const [message, setMessage] = useState(""); const [busy, setBusy] = useState(false);
  useEffect(() => { if (resource.data) { setName(resource.data.user.name); setPhone(resource.data.user.phone || ""); setBirthDate(resource.data.user.birth_date || ""); } }, [resource.data]);
  async function save() {
    setBusy(true); setError(undefined); setMessage("");
    try { const result = await api<{ user: User }>("/profile", "PATCH", { name, phone: phone || null, birth_date: birthDate || null }); updateUser(result.user); setMessage("Perfil atualizado."); await resource.reload(); }
    catch (reason) { setError(reason); } finally { setBusy(false); }
  }
  async function password() {
    if (newPassword !== confirm) { setError(new Error("As novas senhas não coincidem.")); return; }
    setBusy(true); setError(undefined);
    try { await api("/profile/password", "POST", { current_password: currentPassword, new_password: newPassword }); await changePasswordLocallyComplete(); }
    catch (reason) { setError(reason); } finally { setBusy(false); }
  }
  return <Screen>
    <Heading title="Meu perfil" subtitle="Seus dados e seu lugar na comunidade." />
    {resource.loading ? <Loading /> : resource.error ? <ErrorState error={resource.error} retry={resource.reload} /> : resource.data ? <>
      <Card><View style={styles.identity}><View style={styles.avatar}><Text style={styles.avatarText}>{resource.data.user.name[0]}</Text></View><View><Text style={styles.name}>{resource.data.user.name}</Text><Badge value={resource.data.user.role} /></View></View>{message ? <Text accessibilityRole="alert" style={styles.success}>{message}</Text> : null}{error ? <ErrorState error={error} /> : null}
        <Text style={common.label}>Nome completo</Text><TextInput accessibilityLabel="Nome completo" value={name} onChangeText={setName} style={common.input} maxLength={120} />
        <Text style={common.label}>Telefone</Text><TextInput accessibilityLabel="Telefone" value={phone} onChangeText={setPhone} style={common.input} keyboardType="phone-pad" maxLength={40} />
        <Text style={common.label}>Data de nascimento</Text><TextInput accessibilityLabel="Data de nascimento" value={birthDate} onChangeText={setBirthDate} placeholder="AAAA-MM-DD" style={common.input} maxLength={10} />
        <Pressable disabled={busy} onPress={save} style={common.button}><Text style={common.buttonText}>{busy ? "Salvando…" : "Salvar perfil"}</Text></Pressable>
      </Card>
      <Card><Text style={styles.section}>Meus ministérios</Text>{resource.data.ministries.length ? resource.data.ministries.map((m) => <Text key={m.id} style={styles.item}>• {m.name}</Text>) : <Text style={styles.muted}>Você ainda não possui participação ativa.</Text>}<Text style={[styles.section, styles.spacing]}>Minhas lideranças</Text>{resource.data.led_ministries.length ? resource.data.led_ministries.map((m) => <Text key={m.id} style={styles.item}>• {m.name}</Text>) : <Text style={styles.muted}>Nenhuma liderança ativa.</Text>}</Card>
      <Card><Text style={styles.section}>Alterar senha</Text><Text style={common.label}>Senha atual</Text><TextInput secureTextEntry value={currentPassword} onChangeText={setCurrentPassword} style={common.input} /><Text style={common.label}>Nova senha</Text><TextInput secureTextEntry value={newPassword} onChangeText={setNewPassword} style={common.input} /><Text style={common.label}>Confirme a nova senha</Text><TextInput secureTextEntry value={confirm} onChangeText={setConfirm} style={common.input} /><Pressable disabled={busy || !currentPassword || newPassword.length < 12} onPress={password} style={common.button}><Text style={common.buttonText}>Alterar senha e sair</Text></Pressable></Card>
      <Pressable onPress={logout} style={styles.logout}><Text style={styles.logoutText}>Sair da conta</Text></Pressable>
    </> : null}
  </Screen>;
}

const styles = StyleSheet.create({ identity: { flexDirection: "row", alignItems: "center", gap: 13, marginBottom: 20 }, avatar: { width: 54, height: 54, borderRadius: 27, backgroundColor: colors.greenSoft, alignItems: "center", justifyContent: "center" }, avatarText: { color: colors.green, fontWeight: "900", fontSize: 20 }, name: { color: colors.ink, fontSize: 20, fontWeight: "800", marginBottom: 5 }, success: { color: colors.green, fontWeight: "700", marginBottom: 15 }, section: { color: colors.ink, fontSize: 18, fontWeight: "800", marginBottom: 10 }, spacing: { marginTop: 20 }, item: { color: colors.ink, lineHeight: 25 }, muted: { color: colors.muted }, logout: { alignItems: "center", padding: 16 }, logoutText: { color: colors.danger, fontWeight: "800" } });
