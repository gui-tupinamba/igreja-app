import { useState } from "react";
import { KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { ErrorState, common } from "@/components";
import { useSession } from "@/session";
import { colors } from "@/theme";

export default function SignIn() {
  const { login } = useSession();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  async function submit() {
    setBusy(true); setError(undefined);
    try { await login(email.trim(), password); }
    catch (reason) { setError(reason); }
    finally { setBusy(false); }
  }
  return <SafeAreaView style={styles.safe}><KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.page}>
    <View style={styles.brand}><Text style={styles.mark}>⌂</Text><View><Text style={styles.brandName}>Comunidade</Text><Text style={styles.brandTag}>VIDA EM IGREJA</Text></View></View>
    <View style={styles.copy}><Text style={styles.eyebrow}>BEM-VINDO À SUA COMUNIDADE</Text><Text accessibilityRole="header" style={styles.title}>Que bom ter você aqui.</Text><Text style={styles.subtitle}>Entre para acompanhar sua igreja.</Text></View>
    {error ? <ErrorState error={error} /> : null}
    <Text style={common.label}>E-mail</Text><TextInput accessibilityLabel="E-mail" autoCapitalize="none" autoComplete="email" keyboardType="email-address" value={email} onChangeText={setEmail} style={common.input} />
    <Text style={common.label}>Senha</Text><TextInput accessibilityLabel="Senha" autoComplete="current-password" secureTextEntry value={password} onChangeText={setPassword} style={common.input} />
    <Pressable accessibilityRole="button" disabled={busy || !email || !password} onPress={submit} style={({ pressed }) => [common.button, (pressed || busy) && styles.pressed]}><Text style={common.buttonText}>{busy ? "Entrando…" : "Entrar na comunidade"}</Text></Pressable>
    <Text style={styles.help}>Ainda não tem acesso? Fale com a administração da sua igreja.</Text>
  </KeyboardAvoidingView></SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.cream }, page: { flex: 1, justifyContent: "center", padding: 26 },
  brand: { flexDirection: "row", alignItems: "center", gap: 12, marginBottom: 48 }, mark: { color: colors.white, backgroundColor: colors.green, borderRadius: 13, overflow: "hidden", fontSize: 24, padding: 11 },
  brandName: { color: colors.ink, fontSize: 22, fontWeight: "900" }, brandTag: { color: colors.green, fontSize: 9, letterSpacing: 1.8, fontWeight: "800" },
  copy: { marginBottom: 26 }, eyebrow: { color: colors.green, fontSize: 11, letterSpacing: 1.4, fontWeight: "800", marginBottom: 9 }, title: { color: colors.ink, fontSize: 31, lineHeight: 37, fontWeight: "800" }, subtitle: { color: colors.muted, marginTop: 8, fontSize: 16 },
  help: { color: colors.muted, textAlign: "center", marginTop: 26, lineHeight: 21 }, pressed: { opacity: 0.7 },
});
